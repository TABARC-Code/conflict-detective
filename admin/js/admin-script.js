jQuery(function ($) {
  function post(action, data) {
    return $.post(conflictDetectiveData.ajax_url, $.extend({
      action: action,
      nonce: conflictDetectiveData.nonce
    }, data || {}));
  }
  function renderResults(payload) {
    var box = $('#cd-scan-results');
    box.empty();
    box.show();
    var html = '';
    html += '<p><strong>' + conflictDetectiveData.strings.done + '</strong></p>';
    if (payload && payload.conflicts_found !== undefined) {
      html += '<p>Conflicts found: ' + payload.conflicts_found + '</p>';
    }
    html += '<p>Refresh the Dashboard tab to see stored conflicts.</p>';
    box.html(html);
  }
  function pollProgress() {
    post('conflict_detective_get_progress').done(function (res) {
      if (!res || !res.success) return;
      var p = res.data.progress;
      if (!p) return;
      if (p.status === 'running') {
        setTimeout(pollProgress, 1500);
      }
    });
  }
  $('#cd-start-scan').on('click', function (e) {
    e.preventDefault();
    var btn = $(this);
    btn.prop('disabled', true).text(conflictDetectiveData.strings.start);
    post('conflict_detective_start_scan').done(function (res) {
      if (!res || !res.success) {
        alert(conflictDetectiveData.strings.error);
        btn.prop('disabled', false).text('Start automated detection');
        return;
      }
      renderResults({ conflicts_found: res.data.conflicts_found });
      pollProgress();
      btn.prop('disabled', false).text('Start automated detection');
    }).fail(function () {
      alert(conflictDetectiveData.strings.error);
      btn.prop('disabled', false).text('Start automated detection');
    });
  });
  $('#cd-cancel-scan').on('click', function (e) {
    e.preventDefault();
    post('conflict_detective_cancel_scan').always(function () {
      location.reload();
    });
  });
  $(document).on('click', '.cd-resolve', function (e) {
    e.preventDefault();
    var id = $(this).data('conflict-id');
    post('conflict_detective_resolve_conflict', { conflict_id: id }).always(function () {
      location.reload();
    });
  });
  $(document).on('click', '.cd-restore-snapshot', function (e) {
    e.preventDefault();
    var id = $(this).data('snapshot-id');
    post('conflict_detective_restore_snapshot', { snapshot_id: id }).always(function () {
      location.reload();
    });
  });
  $('#cd-create-snapshot').on('click', function (e) {
    e.preventDefault();
    var label = prompt('Snapshot label', 'Manual snapshot');
    if (label === null) return;
    post('conflict_detective_create_snapshot', { label: label }).always(function () {
      location.reload();
    });
  });
});
