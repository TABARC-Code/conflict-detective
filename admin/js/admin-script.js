// admin/js/admin-script.js
jQuery(function($){
  function ajax(action, data){
    return $.post(conflictDetectiveData.ajax_url, $.extend({
      action: action,
      nonce: conflictDetectiveData.nonce
    }, data || {}));
  }
  function showOutput(html){
    var $out = $('#cd-scan-output');
    $out.html(html).show();
  }
  function startScan(){
    showOutput('<p>' + conflictDetectiveData.strings.starting + '</p>');
    ajax('conflict_detective_start_scan', {}).done(function(res){
      if(!res || !res.success){
        showOutput('<p>' + conflictDetectiveData.strings.error + '</p>');
        return;
      }
      showOutput('<pre style="white-space:pre-wrap;margin:0;">' + escapeHtml(JSON.stringify(res.data.results, null, 2)) + '</pre>');
    }).fail(function(){
      showOutput('<p>' + conflictDetectiveData.strings.error + '</p>');
    });
  }
  function escapeHtml(str){
    return String(str).replace(/[&<>"']/g,function(m){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m];
    });
  }
  $('#cd-start-scan, #cd-start-scan-hero').on('click', function(e){
    e.preventDefault();
    startScan();
  });
  $(document).on('click', '.cd-resolve', function(e){
    e.preventDefault();
    var id = $(this).data('conflict-id');
    if(!id){ return; }
    if(!window.confirm(conflictDetectiveData.strings.confirm_resolve)){ return; }
    ajax('conflict_detective_resolve_conflict', { conflict_id: id }).done(function(){
      window.location.reload();
    });
  });
  $('#cd-toggle-safe-mode').on('click', function(e){
    e.preventDefault();
    var safe = $(this).data('safe') === 1 || $(this).data('safe') === '1';
    if(!safe){
      if(!window.confirm(conflictDetectiveData.strings.confirm_safe_mode)){ return; }
    }
    ajax('conflict_detective_toggle_safe_mode', { enable: safe ? 0 : 1 }).done(function(){
      window.location.reload();
    });
  });
  $('#cd-export-latest-json').on('click', function(e){
    e.preventDefault();
    ajax('conflict_detective_export_latest', { format: 'json' }).done(function(res){
      if(res && res.success && res.data && res.data.url){ window.location.href = res.data.url; }
    });
  });
  $('#cd-export-latest-csv').on('click', function(e){
    e.preventDefault();
    ajax('conflict_detective_export_latest', { format: 'csv' }).done(function(res){
      if(res && res.success && res.data && res.data.url){ window.location.href = res.data.url; }
    });
  });
});
