const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/vendor-appointments.js'), 'utf8');
const handlers = [];
const elements = new Map();
function $(key) {
  if (typeof key === 'object') return key;
  if (!elements.has(key)) elements.set(key, {
    checked: false, visible: false,
    ready: '1', attr() { return this.ready; },
    prop(name, value) { this[name] = value; return this; },
    toggle(value) { this.visible = value; return this; },
    hide() { this.visible = false; return this; },
    show() { this.visible = true; return this; },
    is() { return this.checked; }, val() { return 'guest'; },
    empty() { return this; }, html() { return this; },
    on(events, selector, callback) {
      handlers.push({ key, events, selector: typeof selector === 'string' ? selector : '', callback: callback || selector });
      return this;
    },
  });
  return elements.get(key);
}
const consent = $('#koopo-appt-sms-consent');
const invite = $('#koopo-appt-invite-sms');
consent.checked = true; // Simulate browser-restored form state.
vm.runInNewContext(source.slice(source.indexOf('    function syncSmsControls(){'), source.indexOf('    function getTotalDurationMinutes(){')), {
  $, $apptCreate: $('#create'), $apptCreateModal: $('#modal'), $apptDetailsModal: $('#details'),
  apptState: { resourceId: 1 }, apptCreateState: {}, selectedAddonIds: [],
  setCreateModalLoading() {}, loadApptServices: async () => {}, loadApptTimezone: async () => {},
});
assert.equal(consent.checked, false, 'Initial load clears restored consent');
async function run() {
  const open = handlers.find(h => h.key === '#create').callback;
  for (let i = 0; i < 2; i++) {
    consent.checked = invite.checked = true;
    await open();
    assert.equal(consent.checked, false, 'Opening/reopening clears consent');
    assert.equal(invite.checked, false, 'Opening/reopening clears SMS choice');
  }
  for (const selector of ['#koopo-appt-create-cancel', 'input[name="koopo-appt-customer-type"]', '#koopo-appt-guest-phone, #koopo-appt-guest-name, #koopo-appt-guest-email', '#koopo-appt-invite-sms']) {
    consent.checked = true;
    handlers.find(h => h.selector === selector).callback.call(invite);
    assert.equal(consent.checked, false, selector + ' clears consent');
  }
  invite.ready = '0';
  await open();
  assert.equal(invite.disabled, true, 'Unavailable SMS remains disabled');
  assert.equal(consent.disabled, true, 'Unavailable SMS cannot record consent');
  invite.ready = '1';
  invite.checked = true;
  handlers.find(h => h.selector === '#koopo-appt-invite-sms').callback.call(invite);
  assert.equal(consent.checked, false, 'Choosing SMS never supplies consent');
  assert.equal(consent.disabled, false, 'Ready SMS requires separate affirmative consent');
  console.log('SMS consent lifecycle tests passed.');
}
run().catch(error => { console.error(error); process.exitCode = 1; });
