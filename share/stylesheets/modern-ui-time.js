(function () {
  'use strict';

  var timezoneLanguages = {
    'Europe/Rome': 'it-IT',
    'Europe/Vienna': 'de-AT',
    'Europe/Berlin': 'de-DE',
    'Europe/Paris': 'fr-FR',
    'Europe/Madrid': 'es-ES'
  };

  function validTimezone(value) {
    if (typeof value !== 'string' || !value.trim()) return '';
    try {
      return new Intl.DateTimeFormat('en-GB', { timeZone: value.trim() }).resolvedOptions().timeZone;
    } catch (error) { return ''; }
  }

  function validLanguage(value) {
    if (typeof value !== 'string' || !value.trim()) return '';
    try {
      return Intl.DateTimeFormat.supportedLocalesOf([value.trim()])[0] || '';
    } catch (error) { return ''; }
  }

  function resolve() {
    var config = window.NagiosModernUIConfig || {};
    var configuredTimezone = validTimezone(config.timezone);
    var browserTimezone = '';
    try { browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone; } catch (error) {}
    var timezone = configuredTimezone || validTimezone(browserTimezone) || 'UTC';
    var browserLanguage = validLanguage(navigator.language) ||
      validLanguage(navigator.languages && navigator.languages[0]);
    var language = validLanguage(config.language) || (configuredTimezone
      ? timezoneLanguages[timezone] || 'en-GB'
      : browserLanguage || 'en-GB');
    return { timeZone: timezone, language: language };
  }

  window.NagiosModernUITime = { resolve: resolve };
})();
