<?php
/*
 * Copyright (C) 2022 SYSTOPIA GmbH
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as published by
 *  the Free Software Foundation in version 3.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Civi\Api4\Action\BirthdayReminders;

use Civi\Api4\Generic\Result;
use Civi\Api4\Group;

final class sendReminders extends \Civi\Api4\Generic\AbstractAction {

  /**
   * BirthdayReminders::sendReminders() API v4
   *
   * These parameters allow you to dry run your birthday mailing setup
   *
   * @see \Civi\Api4\Generic\AbstractAction
   *
   * @package Civi\Api4\Action\BirthdayReminders
  */


  /**
   * Debug email can be set for testing.
   *
   * All emails will be redirected to this email set using debug_email. Example: all_mails_to@thisdomain.com
   *
   * WARNING: Chances are you want to disable activities using this option
   *
   * @var string
  */
  protected string $debug_email = '';
  protected string $date_filter = '';

  /**
   * Acitivites can be enabled or disabled here.
   *
   * - true:  "successful" or "failed" activities will be suppressed
   * - false: "successful" or "failed" activities will be added to contacts
   *
   * @var bool
  */
  protected bool $disable_activities = false;

  public function _run(Result $result): void {
    $errorCount = 1;
    try {
      $birthdayContacts = new \CRM_Birthdays_BirthdayContacts();
      if (!empty($this->date_filter)) {
        $contacts = $this->getBirthdayContacts(
          $this->debug_email, $this->date_filter
        );
      }
      else {
        $contacts = $birthdayContacts->getBirthdayContactsOfToday($this->debug_email);
      }

      if (!$birthdayContacts->groupHasBirthDateContacts()) {
        $result[] = [
          'error' => ts(
            'There are no contacts in the birthday group or there are contacts where no birth date is set.'
          )
        ];
      }
    }
    catch (\Exception $exception) {
      $contacts = [];
      $result[] = [
        'error' => ts('There is a problem collecting birthday contacts: %1', [1 => $exception])
      ];
    }

    $mailer = new \CRM_Birthdays_Mailer();
    if (!empty($contacts)) {
      $errorCount = $mailer->sendMailsAndWriteActivity(
        $contacts, !$this->disable_activities
      );
    }
    else {
      $errorCount = 0;
    }

    $contactsCount = count($contacts);
    $sendCount = $contactsCount - $errorCount;

    $result[] = [
      'status' => ts(
        'Executed: %1 out of %2 mails/activities processed',
        [1 => $sendCount, 2 => $contactsCount]
      )
    ];
  }

  private function validateDateParams($dateParams): array {
    $function = '';
    $dateParams = strtoupper(trim($dateParams));
    if (strpos($dateParams, '+') === 0) {
      $function = 'DATE_ADD';
      $dateParams = str_replace('+', '', $dateParams);
    }
    else if (strpos($dateParams, '-') === 0) {
      $function = 'DATE_SUB';
      $dateParams = str_replace('-', '', $dateParams);
    }
    else {
      throw new \Exception('Invalid date params, only + or - is allowed.');
    }
    $validDate = explode(' ', $dateParams);
    if (empty($validDate[0]) || !is_numeric($validDate[0])) {
      throw new \Exception('Invalid date params, only numeric is allowed as unit.');
    }

    if (empty($validDate[1]) || !in_array($validDate[1], [
      'WEEK', 'DAY', 'MONTH', 'YEAR'
    ])) {
      throw new \Exception('Invalid date params, only WEEK/DAY/MONTH/YEAR is allowed in iterval');
    }

    return [$function, $dateParams];
  }

  private function getBirthdayContacts($isDebugEmail, $dateParams): array {
    try {
      $limit = '';
      $dayFilter = '1';
      [$function, $interval] = $this->validateDateParams($dateParams);

      if (!empty($isDebugEmail)) {
        // just show up to 10 contacts no matter which birthdate
        $limit = 'LIMIT 10';
      }
      else {
        $year = date('Y');

        $dayFilter = "
          {$function}(DATE_FORMAT(civicrm_contact.birth_date, '{$year}%m%d'), INTERVAL {$interval}) = CURDATE()
        ";
      }

      $sql = " SELECT
          civicrm_contact.id AS contact_id,
          civicrm_contact.birth_date AS birth_date,
          civicrm_email.email AS email
        FROM civicrm_contact
          INNER JOIN civicrm_group_contact group_contact
            ON civicrm_contact.id = group_contact.contact_id
          INNER JOIN civicrm_email
            ON civicrm_contact.id = civicrm_email.contact_id
              AND civicrm_email.is_primary = 1
          WHERE civicrm_contact.contact_type = 'Individual'
            AND civicrm_contact.is_opt_out = 0
            AND civicrm_contact.do_not_email = 0
            AND civicrm_contact.is_deceased = 0
            AND civicrm_contact.is_deleted = 0
            AND group_contact.group_id = %1
            AND civicrm_contact.birth_date IS NOT NULL
            AND {$dayFilter}
            {$limit}
      ";

      $dao = \CRM_Core_DAO::executeQuery(
        $sql, [
          1 => [$this->getGroupIdFromApi(), 'Integer']
        ]
      );

      $contacts = [];
      while ($dao->fetch()) {
        $contacts[$dao->contact_id] = [
          'birth_date' => $dao->birth_date,
          'email' => $isDebugEmail ? '' : $dao->email
        ];
      }
      return $contacts;
    }
    catch (\Exception  $exception) {
      throw new \Exception('SQL query failed: ' . $exception->getMessage());
    }
  }

  /**
   * @throws Exception
   */
  private function getGroupIdFromApi(): int {
    return Group::get()
      ->addSelect('id')
      ->addWhere('name', '=', 'birthday_greeting_recipients_group')
      ->execute()
      ->first()['id'];
  }
}
