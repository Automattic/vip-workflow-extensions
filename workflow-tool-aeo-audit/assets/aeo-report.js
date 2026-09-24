/* AEO-only presentation adapter for the native VIP Workflows report modal. */
(function () {
 'use strict';
 function enhance() {
  document.querySelectorAll('.vip-workflows-results-modal').forEach(function (modal) {
   var heading = modal.querySelector('.components-modal__header-heading');
   if (!heading || heading.textContent.trim() !== window.workflowAeoReport?.label) { return; }
   modal.classList.add('workflow-aeo-report');
   modal.querySelectorAll('.vip-workflows-results-modal__issue--pass, .vip-workflows-results-modal__issue--fail').forEach(function (row) {
    var rule = row.querySelector('.vip-workflows-results-modal__issue-rule');
    var badge = rule && rule.nextElementSibling;
    if (badge) { badge.classList.add('workflow-aeo-report__status'); }
    var icon = row.querySelector('svg');
    if (!icon) { return; }
    icon.classList.add('workflow-aeo-report__native-icon');
    var replacement = row.querySelector('.workflow-aeo-report__icon');
    if (!replacement) {
     replacement = document.createElement('span');
     replacement.className = 'workflow-aeo-report__icon';
     replacement.setAttribute('aria-hidden', 'true');
     icon.insertAdjacentElement('beforebegin', replacement);
    }
    var glyph = row.classList.contains('vip-workflows-results-modal__issue--pass') ? '✅' : '❌';
    if (replacement.textContent !== glyph) { replacement.textContent = glyph; }
   });
  });
 }
 var queued = false;
 var observer = new MutationObserver(function () {
  if (queued) { return; }
  queued = true;
  requestAnimationFrame(function () { queued = false; enhance(); });
 });
 observer.observe(document.body, {childList:true, subtree:true});
 enhance();
 window.addEventListener('pagehide', function () { observer.disconnect(); }, {once:true});
}());
