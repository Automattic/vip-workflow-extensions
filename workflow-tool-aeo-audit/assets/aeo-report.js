/*
 * Tags the native VIP Workflows results modal when it shows this tool, so the
 * scoped CSS can style it. Only direct children of <body> are watched (modals
 * portal there), so typing in the editor never triggers this. Nothing inside
 * the React-owned modal is changed beyond one class.
 */
(function () {
 'use strict';
 function tag(root) {
  if (!(root instanceof Element)) { return; }
  var modals = root.matches('.vip-workflows-results-modal') ? [root] : root.querySelectorAll('.vip-workflows-results-modal');
  Array.prototype.forEach.call(modals, function (modal) {
   var heading = modal.querySelector('.components-modal__header-heading');
   var ours = !!heading && heading.textContent.trim() === window.workflowAeoReport?.label;
   modal.classList.toggle('workflow-aeo-report', ours);
  });
 }
 var observer = new MutationObserver(function (records) {
  records.forEach(function (record) { record.addedNodes.forEach(tag); });
 });
 observer.observe(document.body, {childList: true});
 tag(document.body);
 window.addEventListener('pagehide', function () { observer.disconnect(); }, {once: true});
}());
