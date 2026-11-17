/**
 * Szczegóły zadania kolejki w popupie (ES5 + jQuery, PS 1.6+): strona ustawień i karta zamówienia.
 *
 * Wiersz [data-infsubiekt-task-id] w tabeli zadań; adres AJAX z najbliższego [data-ajax-url].
 * Treść dociągana akcją taskDetails przy każdym kliknięciu. Popup własny, bez modala Bootstrapa
 * (PS 1.6 i strony legacy mają Bootstrap 3, strony Symfony od 1.7.7 Bootstrap 4).
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

  var $overlay = null;
  var request = null;

  function close() {
    if (request) {
      request.abort();
      request = null;
    }
    if ($overlay) {
      $overlay.remove();
      $overlay = null;
    }
    $(document).off('keydown.infsubiektTask');
  }

  function open() {
    close();

    $overlay = $('<div>').css({
      position: 'fixed',
      top: 0,
      left: 0,
      right: 0,
      bottom: 0,
      zIndex: 20000,
      background: 'rgba(0, 0, 0, 0.5)',
      overflowY: 'auto',
      padding: '30px 16px'
    });
    var $box = $('<div>').css({
      position: 'relative',
      maxWidth: '960px',
      margin: '0 auto',
      background: '#fff',
      borderRadius: '4px',
      padding: '20px',
      boxShadow: '0 5px 15px rgba(0, 0, 0, 0.5)'
    });
    var $close = $('<button type="button">&times;</button>').css({
      position: 'absolute',
      top: '8px',
      right: '12px',
      border: 0,
      background: 'none',
      fontSize: '26px',
      lineHeight: 1,
      cursor: 'pointer'
    });

    $box.append($close, $('<div data-infsubiekt-task-body>').text('...'));
    $overlay.append($box).appendTo('body');

    $close.on('click', close);
    $overlay.on('click', function (event) {
      if (event.target === this) {
        close();
      }
    });
    $(document).on('keydown.infsubiektTask', function (event) {
      if (event.keyCode === 27) {
        close();
      }
    });

    return $box.find('[data-infsubiekt-task-body]');
  }

  $(document).on('click', '[data-infsubiekt-task-id]', function (event) {
    // przyciski (Anuluj) i linki (zamówienie) w wierszu działają po swojemu
    if ($(event.target).closest('a, button, input, select').length) {
      return;
    }

    var url = $(this).closest('[data-ajax-url]').data('ajax-url');
    if (!url) {
      return;
    }

    var $body = open();
    request = $.ajax({
      url: url,
      type: 'POST',
      dataType: 'json',
      data: { ajax: 1, action: 'taskDetails', id_task: $(this).data('infsubiekt-task-id') }
    })
      .done(function (response) {
        if (response.ok) {
          $body.html(response.html);
        } else {
          $body.text(response.error || '').css('color', '#c00');
        }
      })
      .fail(function (xhr, status) {
        if (status !== 'abort') {
          $body.text('HTTP ' + xhr.status + ' ' + (xhr.responseText || '')).css('color', '#c00');
        }
      })
      .always(function () {
        request = null;
      });
  });
});
