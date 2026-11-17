/**
 * Panele modułu na kartach zamówienia, klienta i produktu (ES5 + jQuery, PS 1.6+).
 *
 * Panel: [data-infsubiekt-panel] z data-ajax-url i data-infsubiekt-params (wspólne parametry).
 * Przycisk: [data-infsubiekt-action] z nazwą akcji, opcjonalnie data-infsubiekt-params
 * i data-infsubiekt-confirm (pytanie przed wysłaniem; po zgodzie dochodzi confirm=1).
 * Odpowiedź: ok, messages | error | payload, opcjonalnie html do [data-infsubiekt-table].
 */
(function (init) {
  // na stronach Symfony (PS 1.7+) jQuery bywa ładowane dopiero po tym pliku
  if (window.jQuery) {
    init(window.jQuery);
  } else {
    window.addEventListener('load', function () {
      init(window.jQuery);
    });
  }
})(function ($) {
  'use strict';

  function resultText(response) {
    if (response.payload) {
      return JSON.stringify(response.payload, null, 2);
    }
    if (response.messages && response.messages.length) {
      return response.messages.join('\n');
    }

    return response.error || '';
  }

  $(document).on('click', '[data-infsubiekt-panel] [data-infsubiekt-action]', function () {
    var $button = $(this);
    var $panel = $button.closest('[data-infsubiekt-panel]');
    var $buttons = $panel.find('[data-infsubiekt-action]');
    var $result = $panel.find('[data-infsubiekt-result]');
    var confirmText = $button.data('infsubiekt-confirm');
    var data = $.extend(
      { ajax: 1, action: $button.data('infsubiekt-action') },
      $panel.data('infsubiekt-params') || {},
      $button.data('infsubiekt-params') || {}
    );

    if (confirmText) {
      if (!window.confirm(confirmText)) {
        return;
      }
      data.confirm = 1;
    }

    $buttons.prop('disabled', true);
    $result.show().text('...').css('color', '');

    $.ajax({
      url: $panel.data('ajax-url'),
      type: 'POST',
      dataType: 'json',
      data: data
    })
      .done(function (response) {
        if (response.html) {
          $panel.find('[data-infsubiekt-table]').html(response.html);
          $buttons = $panel.find('[data-infsubiekt-action]');
        }
        $result.text(resultText(response)).css('color', response.ok ? '' : '#c00');
      })
      .fail(function (xhr) {
        $result.text('HTTP ' + xhr.status + ' ' + (xhr.responseText || '')).css('color', '#c00');
      })
      .always(function () {
        $buttons.prop('disabled', false);
      });
  });
});
