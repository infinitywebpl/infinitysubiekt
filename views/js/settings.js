/**
 * Strona ustawień modułu: ping agenta, ręczny cron i odświeżanie listy zadań (ES5 + jQuery, PS 1.6+).
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

  $(function () {
    var $root = $('#infsubiekt-settings');
    if (!$root.length) {
      return;
    }

    var ajaxUrl = $root.data('ajax-url');

    function call(action, data) {
      return $.ajax({
        url: ajaxUrl,
        type: 'POST',
        dataType: 'json',
        data: $.extend({ ajax: 1, action: action }, data || {})
      });
    }

    $root.on('click', '[data-infsubiekt-ping]', function () {
      var $button = $(this);
      var $result = $root.find('[data-infsubiekt-ping-result]');

      $button.prop('disabled', true);
      $result.show().text('...');

      call('ping')
        .done(function (response) {
          var details = JSON.stringify(response.result, null, 2);
          $result.text(response.hint ? response.hint + '\n\n' + details : details);
          $result.css('color', response.ok ? '' : '#c00');
        })
        .fail(function (xhr) {
          $result.text('HTTP ' + xhr.status + ' ' + (xhr.responseText || '')).css('color', '#c00');
        })
        .always(function () {
          $button.prop('disabled', false);
        });
    });

    // zdalna aktualizacja agenta i powrót do poprzedniej wersji (czeka na wynik agenta do 150 s)
    $root.on('click', '[data-infsubiekt-agent-action]', function () {
      var $button = $(this);
      var $buttons = $root.find('[data-infsubiekt-agent-action]');
      var $result = $root.find('[data-infsubiekt-agent-result]');
      if (!window.confirm($button.data('infsubiekt-confirm'))) {
        return;
      }

      $buttons.prop('disabled', true);
      $result.show().text('...').css('color', '');

      call($button.data('infsubiekt-agent-action'))
        .done(function (response) {
          $result.text(response.ok ? JSON.stringify(response.result, null, 2) : (response.error || ''));
          $result.css('color', response.ok ? '' : '#c00');
        })
        .fail(function (xhr) {
          $result.text('HTTP ' + xhr.status + ' ' + (xhr.responseText || '')).css('color', '#c00');
        })
        .always(function () {
          $buttons.prop('disabled', false);
        });
    });

    $root.on('click', '[data-infsubiekt-cron-run]', function () {
      var $buttons = $root.find('[data-infsubiekt-cron-run]');
      var $result = $root.find('[data-infsubiekt-cron-result]');

      $buttons.prop('disabled', true);
      $result.show().text('...').css('color', '');

      call('cronRun', { dry_run: $(this).data('infsubiekt-cron-run') })
        .done(function (response) {
          $result.text((response.lines || []).join('\n'));
          $result.css('color', response.status === 'ok' ? '' : '#c00');
        })
        .fail(function (xhr) {
          $result.text('HTTP ' + xhr.status + ' ' + (xhr.responseText || '')).css('color', '#c00');
        })
        .always(function () {
          $buttons.prop('disabled', false);
        });
    });

    function refreshTasks() {
      var $table = $root.find('[data-infsubiekt-tasks-table]');
      var status = $root.find('[data-infsubiekt-tasks-status]').val();

      $table.css('opacity', 0.5);
      call('tasks', { status: status })
        .done(function (response) {
          $table.html(response.html);
        })
        .always(function () {
          $table.css('opacity', 1);
        });
    }

    $root.on('click', '[data-infsubiekt-action="cancelTask"]', function () {
      var $button = $(this);
      if (!window.confirm($button.data('infsubiekt-confirm'))) {
        return;
      }

      $button.prop('disabled', true);
      call('cancelTask', $button.data('infsubiekt-params'))
        .done(function (response) {
          window.alert(response.messages ? response.messages.join('\n') : (response.error || ''));
        })
        .fail(function (xhr) {
          window.alert('HTTP ' + xhr.status + ' ' + (xhr.responseText || ''));
        })
        .always(refreshTasks);
    });

    $root.on('click', '[data-infsubiekt-tasks-refresh]', refreshTasks);
    $root.on('change', '[data-infsubiekt-tasks-status]', refreshTasks);

    // cecha stanów: lista cech z Subiekta podmienia opcje selecta, wybór zostaje (zapis dopiero przyciskiem Zapisz)
    $root.on('click', '[data-infsubiekt-feature-fetch]', function () {
      var $button = $(this);
      var $status = $root.find('[data-infsubiekt-feature-status]');
      var $select = $root.find('select[name="INFSUBIEKT_STOCK_FEATURE"]');

      $button.prop('disabled', true);
      $status.text('...').css('color', '');

      call('stockFeatures')
        .done(function (response) {
          if (!response.ok) {
            $status.text(response.error || '').css('color', '#c00');
            return;
          }
          var current = $select.val() || '';
          var found = false;
          var kept = $select.find('option').filter(function () {
            return this.value === current;
          }).first().clone();

          $select.empty();
          $.each(response.options || [], function (i, option) {
            $select.append($('<option>').val(option.value).text(option.label));
            if (option.value.toLowerCase() === current.toLowerCase()) {
              found = true;
              current = option.value;
            }
          });
          if (!found && kept.length) {
            $select.append(kept);
          }
          $select.val(current);
          $status.text($status.data('label-fetched') + ' ' + response.fetched_at + ' (' + response.count + ' ' + $status.data('label-features') + ')').css('color', '');
        })
        .fail(function (xhr) {
          $status.text('HTTP ' + xhr.status + ' ' + (xhr.responseText || '')).css('color', '#c00');
        })
        .always(function () {
          $button.prop('disabled', false);
        });
    });

    // mapa płatności: lista form z Subiekta podmienia opcje selectów, wybór zostaje (zapis dopiero przyciskiem Zapisz)
    $root.on('click', '[data-infsubiekt-payment-fetch]', function () {
      var $button = $(this);
      var $map = $button.closest('[data-infsubiekt-payment-map]');
      var $status = $map.find('[data-infsubiekt-payment-status]');

      $button.prop('disabled', true);
      $status.text('...').css('color', '');

      call('paymentForms')
        .done(function (response) {
          if (!response.ok) {
            $status.text(response.error || '').css('color', '#c00');
            return;
          }
          $map.find('[data-infsubiekt-payment-select]').each(function () {
            var $select = $(this);
            var current = $select.val() || '';
            // wartości spoza nowej listy zostają jako opcje, żeby zapis ich nie zgubił
            var kept = $select.find('option').filter(function () {
              return this.value === current;
            }).first().clone();
            var found = false;

            $select.empty();
            $.each(response.options || [], function (i, option) {
              $select.append($('<option>').val(option.value).text(option.label));
              if (option.value === current) {
                found = true;
              }
            });
            if (!found && kept.length) {
              $select.append(kept);
            }
            $select.val(current);
          });
          $status.text($status.data('label-fetched') + ' ' + response.fetched_at + ' (' + response.count + ' ' + $status.data('label-forms') + ')').css('color', '');
        })
        .fail(function (xhr) {
          $status.text('HTTP ' + xhr.status + ' ' + (xhr.responseText || '')).css('color', '#c00');
        })
        .always(function () {
          $button.prop('disabled', false);
        });
    });

    // szablony tekstów dokumentu: klik w tag wstawia go w ostatnio wybranym polu (domyślnie pierwszym)
    var $tagHelp = $root.find('[data-infsubiekt-tag-fields]');
    if ($tagHelp.length) {
      var tagFields = $.map(String($tagHelp.data('infsubiekt-tag-fields')).split(','), function (id) {
        return document.getElementById(id);
      });
      var lastField = tagFields[0] || null;

      $(tagFields).on('focus', function () {
        lastField = this;
      });

      $tagHelp.on('click', '[data-infsubiekt-tag]', function () {
        if (!lastField) {
          return;
        }
        var tag = String($(this).data('infsubiekt-tag'));
        var value = lastField.value;
        var start = typeof lastField.selectionStart === 'number' ? lastField.selectionStart : value.length;
        var end = typeof lastField.selectionEnd === 'number' ? lastField.selectionEnd : value.length;

        lastField.value = value.slice(0, start) + tag + value.slice(end);
        lastField.focus();
        lastField.setSelectionRange(start + tag.length, start + tag.length);
      });
    }
  });
});
