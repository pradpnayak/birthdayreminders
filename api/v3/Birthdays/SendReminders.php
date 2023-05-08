<?php

/**
 * Birthdays::sendreminders() API v3
 *
 * This API is calling an API v4
 *
 * These parameters allow you to dry run your birthday mailing setup
 *
 **/
function _civicrm_api3_birthdays_send_reminders_spec(&$params) {
  $params['debug_email'] = [
    'name' => 'debug_email',
    'api.required' => 0,
    'type' => CRM_Utils_Type::T_STRING,
    'title' => ts('Set a debug email address (default: empty)'),
    'description'  => ts('A debug email is used to redirect all mails to this address')
  ];
  $params['disable_activities'] = [
    'name' => 'disable_activities',
    'api.required' => 0,
    'type' => CRM_Utils_Type::T_BOOLEAN,
    'title' => ts('Option to disable activities (default: no)'),
    'description'  => ts('Should activities be disabled?')
  ];
}


/**
 * birthdays.sendreminders
 *
 * @param array $params
 *   API call parameters
 *
 * @return array
 *   API3 response
 *
 */
function civicrm_api3_birthdays_send_reminders(array $params): array {
  try {
    $api_status_info = \Civi\Api4\BirthdayReminders::sendReminders()
      ->setDisable_activities((boolval($params['disable_activities'] ?? FALSE)))
      ->setDebug_email(($params['debug_email'] ?? ''))
      ->setDate_filter(($params['date_filter'] ?? ''))
      ->execute()
      ->first();

    if (!empty($api_status_info['error'])) {
      return civicrm_api3_create_error(ts(
        'Rethrow error from APIv4: %1',
        [1 => $api_status_info['error']]
      ));
    }

    return civicrm_api3_create_success($api_status_info, $params, 'birthdays', 'send_reminders');
  }
  catch (Exception $exception) {
    return civicrm_api3_create_error(ts(
      'Error found in APIv3 wrapper calling APIv4: %1',
      [1 => $exception->getMessage()]
    ));
  }
}
