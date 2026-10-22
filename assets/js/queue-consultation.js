/* Inline consultation controller. Queue polling never replaces clinical form fields. */
let consultationDirty = false;
let consultationStarted = false;
let consultationBusy = false;
let consultationLoading = false;
let consultationVisitId = null;
let consultationClosed = false;
const consultationForm = document.getElementById('unifiedConsultationForm');
const consultationStatus = document.getElementById('consultationSaveStatus');
consultationForm.addEventListener('input', () => { consultationDirty = true; });
consultationForm.addEventListener('change', () => { consultationDirty = true; });
window.addEventListener('beforeunload', event => {
  if (consultationDirty) { event.preventDefault(); event.returnValue = ''; }
});

async function workspaceRequest(action, values = {}, endpoint = 'queue.php') {
  const data = values instanceof FormData ? values : new FormData();
  if (!(values instanceof FormData)) Object.entries(values).forEach(([key, value]) => data.set(key, value));
  data.set('action', action);
  data.set('csrf_token', csrfToken);
  const response = await fetch(`${APP_URL}/api/${endpoint}`, { method: 'POST', body: data });
  const result = await response.json();
  if (!result.success) throw new Error(result.message || 'Unable to save. Your entries have been retained.');
  return result.data || result;
}

function consultationMedicines() {
  return [...document.querySelectorAll('#medicinesContainer .med-dispense-row')].map(row => ({
    medicine_id: Number(row.querySelector('.med-select').value),
    quantity: Number(row.querySelector('.med-qty').value),
    dosage_instructions: row.querySelector('.med-dosage').value
  }));
}

function setConsultationControls() {
  ['saveConsultationDraftBtn', 'submitConsultationBtn', 'cancelConsultationBtn', 'generateSummaryBtn'].forEach(id => {
    document.getElementById(id).disabled = consultationBusy || !consultationStarted || consultationClosed;
  });
  document.getElementById('startWorkspaceBtn').disabled = consultationBusy || consultationClosed || consultationStarted;
}

async function openIntegratedConsultation(ticketId, start) {
  if (consultationLoading || consultationBusy) return;
  const same = String(document.getElementById('consultTicketId').value) === String(ticketId);
  if (same) { if (start && !consultationStarted) await startWorkspaceConsultation(); return; }
  if (consultationDirty && !confirm('Leave this patient and discard unsaved changes? Save Draft first to retain them.')) return;
  consultationLoading = true;
  try {
    const response = await fetch(`${APP_URL}/api/queue.php?action=get_patient_medical_summary&ticket_id=${encodeURIComponent(ticketId)}`);
    const result = await response.json();
    if (!result.success) throw new Error(result.message || 'Unable to load consultation.');
    const { patient: p, medical_record: medical, visit, latest_vitals: latest, recent_visits: history, dispensing, draft_options: options } = result.data;
    consultationForm.reset();
    consultationDirty = false;
    consultationVisitId = visit?.id || null;
    consultationClosed = ['completed', 'cancelled', 'no_show'].includes(p.status) || ['completed', 'cancelled'].includes(visit?.status);
    consultationStarted = p.status === 'serving' && !consultationClosed;
    document.getElementById('consultTicketId').value = ticketId;
    document.getElementById('consultDraftVersion').value = result.data.draft_version || '';
    availableMedicines = result.data.medicines || [];
    document.getElementById('patientFullNameText').textContent = p.patient_name;
    document.getElementById('patientAvatarLetter').textContent = p.patient_name.charAt(0).toUpperCase();
    document.getElementById('patientIdentifiersText').textContent = [p.student_number || p.employee_id, p.course || p.department || visit?.temp_course, p.year_level || visit?.temp_year_level, p.section, visit?.temp_contact, p.age != null ? `Age ${p.age}` : '', p.gender].filter(Boolean).join(' · ');
    document.getElementById('pBloodType').textContent = medical?.blood_type || 'Unknown';
    document.getElementById('pAllergiesBadge').textContent = `Allergies: ${medical?.allergies || 'None recorded'}`;
    document.getElementById('pAllergiesBadge').className = medical?.allergies && !['None', 'N/A'].includes(medical.allergies) ? 'badge bg-danger' : 'badge bg-success';
    for (const field of ['chief_complaint', 'symptoms', 'assessment', 'diagnosis', 'treatment', 'recommendation', 'digital_signature', 'follow_up_date']) {
      consultationForm.elements[field].value = visit?.[field] || (field === 'chief_complaint' ? p.initial_complaint || '' : '');
    }
    consultationForm.elements.ai_summary.value = visit?.ai_summary_edited ?? visit?.ai_summary ?? '';
    consultationForm.elements.need_follow_up.checked = Number(visit?.need_follow_up) === 1;
    const vitals = visit || latest || {};
    for (const field of ['temperature', 'oxygen_saturation', 'heart_rate', 'respiratory_rate', 'height_cm', 'weight_kg', 'blood_pressure_systolic', 'blood_pressure_diastolic']) {
      consultationForm.elements[field].value = vitals[field] ?? '';
    }
    if (!visit && latest?.blood_pressure) {
      const [sys, dia] = latest.blood_pressure.split('/');
      consultationForm.elements.blood_pressure_systolic.value = sys || '';
      consultationForm.elements.blood_pressure_diastolic.value = dia || '';
    }
    document.getElementById('medicinesContainer').innerHTML = '<div class="text-muted small" id="noMedsHint">No medicines selected. Add medication to dispense on completion.</div>';
    for (const medicine of options?.medicines || []) {
      addMedicineRow();
      const row = document.querySelector('#medicinesContainer .med-dispense-row:last-child');
      row.querySelector('.med-select').value = medicine.medicine_id;
      row.querySelector('.med-qty').value = medicine.quantity;
      row.querySelector('.med-dosage').value = medicine.dosage_instructions || '';
    }
    consultationForm.elements.issue_certificate.checked = !!options?.issue_certificate;
    consultationForm.elements.certificate_type.value = options?.certificate_type || 'Clinic Visit Pass';
    consultationForm.elements.certificate_purpose.value = options?.certificate_purpose || '';
    toggleFollowUpInput(); toggleCertificateInputs(); calculateLiveBmi();
    const records = (history || []).map(item => `<p>${escapeHtml(item.consultation_date)}: ${escapeHtml(item.chief_complaint)} — ${escapeHtml(item.diagnosis || '')}<br>${escapeHtml(item.treatment || '')} · ${escapeHtml(item.nurse_name || '')}</p>`);
    const meds = (dispensing || []).map(item => `<p>${escapeHtml(item.medicine_name)} × ${Number(item.quantity)} — ${escapeHtml(item.dosage_instructions || '')} (${escapeHtml(item.dispensed_at || '')})</p>`);
    document.getElementById('consultHistory').innerHTML = '<h6>Recent consultations</h6>' + (records.join('') || '<p>No prior consultations recorded.</p>') + '<h6>Already dispensed for this visit</h6>' + (meds.join('') || '<p>No medicines dispensed.</p>');
    document.getElementById('consultationSelected').hidden = false;
    document.getElementById('consultationEmptyState').hidden = true;
    consultationStatus.textContent = consultationClosed ? 'This visit is closed. The saved record is available for review.' : 'Ready. Save Draft to keep your progress.';
    for (const control of consultationForm.querySelectorAll('input:not([type=hidden]), textarea, select')) control.disabled = consultationClosed;
    document.getElementById('consultModalSubtitle').textContent = `Ticket ${p.queue_number} · ${p.status.toUpperCase()}${visit ? ' · ' + visit.visit_number : ''}`;
    consultationDirty = false;
    setConsultationControls();
    if (start && !consultationClosed && !consultationStarted) await startWorkspaceConsultation();
    if (window.innerWidth < 1200) document.getElementById('consultationWorkspaceModal').scrollIntoView({ behavior: 'smooth', block: 'start' });
  } catch (error) { alert(error.message); }
  finally { consultationLoading = false; }
}

async function startWorkspaceConsultation() {
  if (consultationBusy || consultationClosed) return;
  consultationBusy = true; setConsultationControls();
  try {
    const result = await workspaceRequest('start_serving', { ticket_id: document.getElementById('consultTicketId').value, draft_version: document.getElementById('consultDraftVersion').value });
    consultationVisitId = result.walk_in_id;
    consultationStarted = true;
    document.getElementById('consultDraftVersion').value = result.draft_version;
    consultationStatus.textContent = 'Consultation started. Save Draft to keep your progress.';
    loadQueueList();
  } catch (error) { alert(error.message); }
  finally { consultationBusy = false; setConsultationControls(); }
}

async function saveConsultationDraft() {
  if (consultationBusy || !consultationStarted || consultationClosed) return false;
  if (!consultationForm.reportValidity()) return false;
  consultationBusy = true; setConsultationControls();
  const data = new FormData(consultationForm);
  data.set('medicines', JSON.stringify(consultationMedicines()));
  // Inputs remain editable during the request; only clear dirty if nothing changed.
  const snapshot = JSON.stringify([...data.entries()]);
  try {
    const result = await workspaceRequest('save_consultation_draft', data);
    const current = new FormData(consultationForm); current.set('medicines', JSON.stringify(consultationMedicines()));
    if (JSON.stringify([...current.entries()]) === snapshot) consultationDirty = false;
    consultationVisitId = result.walk_in_id;
    document.getElementById('consultDraftVersion').value = result.draft_version;
    consultationStatus.textContent = consultationDirty ? 'Draft saved. Newer edits are unsaved.' : 'Draft saved. You can resume this visit from the queue.';
    loadQueueList(); return true;
  } catch (error) { consultationStatus.textContent = error.message; return false; }
  finally { consultationBusy = false; setConsultationControls(); }
}

async function generateConsultationSummary() {
  if (!await saveConsultationDraft()) return;
  consultationBusy = true; setConsultationControls();
  try {
    const result = await workspaceRequest('generate_ai_summary', { id: consultationVisitId, draft_version: document.getElementById('consultDraftVersion').value }, 'walkin.php');
    document.getElementById('aiSummary').value = result.summary;
    consultationDirty = true;
    document.getElementById('consultDraftVersion').value = result.draft_version;
    consultationStatus.textContent = 'Summary generated. Review and save your edited summary.';
  } catch (error) { consultationStatus.textContent = error.message; }
  finally { consultationBusy = false; setConsultationControls(); }
}

async function dispenseWorkspaceMedicine(button) {
  const row = button.closest('.med-dispense-row');
  if (!row || !consultationStarted || consultationClosed || consultationBusy) return;
  if (!confirm('Dispense this medicine now and deduct it from inventory?')) return;
  consultationForm.inert = true;
  if (!await saveConsultationDraft()) { consultationForm.inert = false; return; }
  consultationBusy = true; setConsultationControls();
  try {
    const medicineId = Number(row.querySelector('.med-select').value);
    const quantity = Number(row.querySelector('.med-qty').value);
    await workspaceRequest('dispense_medicine', {
      walk_in_id: consultationVisitId, medicine_id: medicineId, quantity,
      dosage_instructions: row.querySelector('.med-dosage').value,
      draft_row_index: [...document.querySelectorAll('#medicinesContainer .med-dispense-row')].indexOf(row)
    }, 'walkin.php');
    row.remove(); consultationDirty = true;
    availableMedicines = availableMedicines.map(m => Number(m.id) === medicineId ? { ...m, quantity: Number(m.quantity) - quantity } : m);
    const note = document.createElement('p');
    note.textContent = `Dispensed now: ${availableMedicines.find(m => Number(m.id) === medicineId)?.medicine_name || medicineId} ? ${quantity}`;
    document.getElementById('consultHistory').append(note);
    consultationStatus.textContent = 'Medicine dispensed and recorded. It will not be deducted again on completion.';
  } catch (error) { consultationStatus.textContent = error.message; }
  finally { consultationForm.inert = false; consultationBusy = false; setConsultationControls(); }
}

function closeConsultationWorkspace() {
  if (consultationBusy || consultationLoading) return false;
  if (consultationDirty && !confirm('Close without saving your latest changes?')) return false;
  consultationDirty = false;
  document.getElementById('consultTicketId').value = '';
  document.getElementById('consultationSelected').hidden = true;
  document.getElementById('consultationEmptyState').hidden = false;
  return true;
}

async function cancelWorkspaceVisit() {
  if (consultationBusy || consultationClosed || !confirm('Cancel this visit? Save Draft first to retain any unsaved entries.')) return;
  consultationBusy = true; setConsultationControls();
  try {
    await workspaceRequest('cancel_ticket', { ticket_id: document.getElementById('consultTicketId').value });
    consultationDirty = false; consultationBusy = false;
    closeConsultationWorkspace(); loadQueueList();
  } catch (error) { alert(error.message); }
  finally { consultationBusy = false; setConsultationControls(); }
}

function syncSelectedPatientStatus() {
  const ticketId = document.getElementById('consultTicketId').value;
  const selected = queueData.find(item => String(item.id) === ticketId);
  if (!selected) return;
  document.getElementById('consultModalSubtitle').textContent = `Ticket ${selected.queue_number} · ${selected.status.toUpperCase()}`;
  consultationClosed = ['completed', 'cancelled', 'no_show'].includes(selected.status);
  if (consultationClosed) consultationStatus.textContent = 'This visit has been closed. Your local entries remain visible; refresh the patient to review the saved record.';
  if (selected.status !== 'serving') consultationStarted = false;
  setConsultationControls();
}

const startWorkspaceButton = document.createElement('button');
startWorkspaceButton.id = 'startWorkspaceBtn'; startWorkspaceButton.type = 'button';
startWorkspaceButton.className = 'btn btn-success'; startWorkspaceButton.textContent = 'Start Consultation';
startWorkspaceButton.addEventListener('click', startWorkspaceConsultation);
document.getElementById('saveConsultationDraftBtn').before(startWorkspaceButton);

// Reuse the original detailed walk-in fields and existing save_visit/search APIs.
const triageForm = document.getElementById('triageWalkinForm');
let triageSearchTimer;
document.getElementById('triageStudentSearch').addEventListener('input', event => {
  clearTimeout(triageSearchTimer);
  const query = event.target.value.trim();
  const results = document.getElementById('triageSearchResults');
  results.replaceChildren();
  if (query.length < 2) return;
  triageSearchTimer = setTimeout(async () => {
    try {
      const response = await fetch(`${APP_URL}/api/walkin.php?action=search_students&q=${encodeURIComponent(query)}`);
      const result = await response.json();
      if (event.target.value.trim() !== query) return;
      results.replaceChildren();
      for (const student of result.data || []) {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'list-group-item list-group-item-action';
        button.textContent = `${student.first_name} ${student.last_name} (${student.student_number})`;
        button.onclick = () => {
          triageForm.elements.student_id.value = student.id;
          document.getElementById('triageSelStudentName').textContent = `${student.first_name} ${student.last_name}`;
          document.getElementById('triageSelStudentDetails').textContent = [student.student_number, student.course, student.year_level].filter(Boolean).join(' · ');
          document.getElementById('triageStudentInfoCard').classList.remove('d-none');
          document.getElementById('triageTempVisitorForm').classList.add('d-none'); results.replaceChildren();
        }; results.append(button);
      }
      const guest = document.createElement('button'); guest.type = 'button'; guest.className = 'list-group-item list-group-item-action'; guest.textContent = 'Add as temporary visitor';
      guest.onclick = () => { triageForm.elements.student_id.value = ''; triageForm.elements.temp_full_name.value = query; document.getElementById('triageTempVisitorForm').classList.remove('d-none'); document.getElementById('triageStudentInfoCard').classList.add('d-none'); results.replaceChildren(); };
      results.append(guest);
    } catch (error) { results.textContent = 'Unable to search students. Please try again.'; }
  }, 250);
});
document.getElementById('triageClearStudentBtn').onclick = () => { triageForm.elements.student_id.value = ''; document.getElementById('triageStudentInfoCard').classList.add('d-none'); };
document.getElementById('triageQrScanBtn').onclick = () => { BPCModal.hide('walkinModal'); openQrScannerModal(); };
document.getElementById('triageNeedFollowUp').onchange = event => document.getElementById('triageFollowUpDateDiv').classList.toggle('d-none', !event.target.checked);
triageForm.addEventListener('input', () => {
  const h = Number(triageForm.elements.height_cm.value), w = Number(triageForm.elements.weight_kg.value);
  const bmi = h > 0 && w > 0 ? w / ((h / 100) ** 2) : null;
  document.getElementById('triageBmiDisplay').textContent = bmi ? `${bmi.toFixed(2)} (${bmi < 18.5 ? 'Underweight' : bmi < 25 ? 'Normal' : bmi < 30 ? 'Overweight' : 'Obese'})` : 'Auto-calculate';
});
document.getElementById('triageSaveWalkinBtn').onclick = async event => {
  if (!triageForm.reportValidity()) return;
  if (!triageForm.elements.student_id.value && !triageForm.elements.temp_full_name.value.trim()) { alert('Select a student or enter temporary visitor details.'); return; }
  const button = event.currentTarget; button.disabled = true;
  try {
    const result = await workspaceRequest('save_visit', new FormData(triageForm), 'walkin.php');
    BPCModal.hide('walkinModal'); triageForm.reset();
    document.getElementById('triageStudentInfoCard').classList.add('d-none');
    document.getElementById('triageTempVisitorForm').classList.add('d-none');
    loadQueueList(); openIntegratedConsultation(result.queue_id, false);
  } catch (error) { alert(error.message); }
  finally { button.disabled = false; }
};

(async () => {
  const query = new URLSearchParams(window.location.search);
  if (query.has('ticket_id')) await openIntegratedConsultation(Number(query.get('ticket_id')), false);
  else if (query.has('id') || query.has('visit')) {
    try {
      const response = await fetch(`${APP_URL}/api/walkin.php?action=get_visit&id=${encodeURIComponent(query.get('id') || query.get('visit'))}`);
      const result = await response.json();
      if (result.success && result.data?.queue_id) await openIntegratedConsultation(result.data.queue_id, false);
    } catch (error) { console.error(error); }
  }
})();
