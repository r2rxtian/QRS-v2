// scan.js — single-scan QR flow: scan -> answer the Observation/
// Recommendation checklist -> do the work -> come back and submit photos
// (max 3) + a biometrics confirmation. No re-scan to finish.

let photoStream = null;
let html5QrCode = null;
let capturedPhotosData = [];
let completionPhotosData = []; // accumulates File objects across BOTH gallery picks and camera captures — a native <input type=file> replaces its FileList on every re-pick, so this is the source of truth
const MAX_COMPLETION_PHOTOS = 3;
const CHECKLIST_KEYS = ['spot_spray', 'misting', 'mist_blower', 'monitoring'];
const CHECKLIST_LABELS = { spot_spray: 'Spot Spray', misting: 'Misting', mist_blower: 'Mist Blower', monitoring: 'Monitoring' };
const CHECKLIST_ICONS = { spot_spray: 'fa-spray-can', misting: 'fa-cloud-rain', mist_blower: 'fa-fan', monitoring: 'fa-eye' };
let checklistState = {};

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function showModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

function showMessage(message, type = 'info') {
    const titleEl = document.getElementById('msgTitle');
    const bodyEl = document.getElementById('msgBody');
    titleEl.textContent = type === 'success' ? 'Success' : type === 'error' ? 'Error' : 'Notification';
    bodyEl.textContent = message;
    showModal('messageModal');
}

// ---------------------------------------------------------------------
// State transitions
// ---------------------------------------------------------------------

function hideAllStates() {
    document.getElementById('landingState').style.display = 'none';
    document.getElementById('methodSelectionState').style.display = 'none';
    document.getElementById('completionState').style.display = 'none';
}

// "You are here" marker in the Locations Status sidebar -- points at
// whichever location's checklist/completion screen is open in the main
// panel right now, independent of that location's DB status (a
// freshly-scanned location is still 'pending' server-side until its
// checklist is actually submitted, so the sidebar's own status-based
// highlighting has nothing to show yet at that point).
function markCurrentLocationInSidebar(taskLocationId) {
    document.querySelectorAll('.scan-location-list-item.here').forEach(el => el.classList.remove('here'));
    if (taskLocationId === null) return;
    const row = document.querySelector('.scan-location-list-item[data-task-location-id="' + taskLocationId + '"]');
    if (row) row.classList.add('here');
}

function returnToLanding() {
    hideAllStates();
    document.getElementById('landingState').style.display = '';
    markCurrentLocationInSidebar(null);
}

function showMethodSelectionState(locationName, taskLocationId) {
    hideAllStates();
    document.getElementById('methodLocationName').textContent = locationName;
    document.getElementById('method_task_location_id').value = taskLocationId;
    markCurrentLocationInSidebar(taskLocationId);
    document.getElementById('findings_observation').value = '';

    checklistState = {};
    document.querySelectorAll('#checklistQuestions .checklist-question').forEach(function (q) {
        q.querySelectorAll('.answer-btn').forEach(b => b.classList.remove('selected'));
        const remark = q.querySelector('.item-remark');
        remark.value = '';
        remark.style.display = 'none';
    });
    document.getElementById('startCheckBtn').disabled = true;

    document.getElementById('methodSelectionState').style.display = '';
}

function showCompletionState(locationName, taskLocationId, startedWith) {
    hideAllStates();
    document.getElementById('completionLocationName').textContent = locationName;
    document.getElementById('completion_task_location_id').value = taskLocationId;
    markCurrentLocationInSidebar(taskLocationId);
    document.getElementById('completion_remark').value = '';
    document.getElementById('confirm_code').value = '';
    document.getElementById('completionPhotoContainer').innerHTML = '';
    document.getElementById('completion_photos').value = '';
    completionPhotosData = [];
    updatePhotoCountLabel();
    document.getElementById('completionRemarkCount').textContent = '0';
    resetConfirmCodeVisibility();

    const box = document.getElementById('completionStartedWithBox');
    const summaryEl = document.getElementById('completionStartedChecklist');
    const checklist = startedWith && startedWith.checklist;

    if (checklist) {
        const answerClass = function (answer) {
            if (answer === 'Yes') return 'yes';
            if (answer === 'No') return 'no';
            return 'na';
        };

        const itemsHtml = CHECKLIST_KEYS.map(function (key) {
            const item = checklist[key] || {};
            if (!item.answer) return '';
            const remarkHtml = item.remark ? '<p class="recorded-start-item-remark">' + escapeHtml(item.remark) + '</p>' : '';
            return '<div class="recorded-start-item">' +
                '<div class="recorded-start-item-label"><i class="fas ' + CHECKLIST_ICONS[key] + '"></i>' + CHECKLIST_LABELS[key] + '</div>' +
                '<span class="recorded-start-answer ' + answerClass(item.answer) + '">' + escapeHtml(item.answer) + '</span>' +
                remarkHtml +
                '</div>';
        }).filter(Boolean).join('');

        let findingsHtml = '';
        if (startedWith.findings_observation) {
            findingsHtml = '<div class="recorded-start-findings"><strong>Findings / Observation</strong>' + escapeHtml(startedWith.findings_observation) + '</div>';
        }

        summaryEl.innerHTML = '<div class="recorded-start-row">' + itemsHtml + '</div>' + findingsHtml;
        box.style.display = '';
    } else {
        box.style.display = 'none';
    }

    document.getElementById('completionState').style.display = '';
}

// ---------------------------------------------------------------------
// Location resolution (QR scan or manual tap) -> ask the server what to do
// ---------------------------------------------------------------------

async function resolveLocation(payload) {
    const formData = new FormData();
    formData.set('csrf_token', QRS_CSRF_TOKEN);
    formData.set('task_id', QRS_TASK_ID);
    Object.keys(payload).forEach(key => formData.set(key, payload[key]));

    try {
        const response = await fetch('../api/scan/lookup.php', { method: 'POST', body: formData });
        const data = await response.json();

        if (!data.success) {
            showMessage(data.message, data.type || 'error');
            return;
        }

        if (navigator.vibrate) navigator.vibrate(200);

        const { stage, task_location_id, location_name, checklist, findings_observation } = data.data;
        if (stage === 'method_selection') {
            showMethodSelectionState(location_name, task_location_id);
        } else if (stage === 'completion') {
            showCompletionState(location_name, task_location_id, { checklist, findings_observation });
        } else {
            showMessage(data.message, 'info');
        }
    } catch (err) {
        showMessage('Could not reach the server. Please try again.', 'error');
    }
}


// ---------------------------------------------------------------------
// Observation/Recommendation checklist
// ---------------------------------------------------------------------

function checklistIsComplete() {
    return CHECKLIST_KEYS.every(function (key) {
        const state = checklistState[key];
        if (!state || !state.answer) return false;
        if ((state.answer === 'No' || state.answer === 'N/A') && !state.remark) return false;
        return true;
    });
}

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('checklistQuestions').addEventListener('click', function (e) {
        const btn = e.target.closest('.answer-btn');
        if (!btn) return;

        const question = btn.closest('.checklist-question');
        const key = question.dataset.item;
        const answer = btn.dataset.answer;
        const remarkField = question.querySelector('.item-remark');

        question.querySelectorAll('.answer-btn').forEach(b => b.classList.remove('selected'));
        btn.classList.add('selected');

        const needsRemark = answer === 'No' || answer === 'N/A';
        remarkField.style.display = needsRemark ? '' : 'none';

        checklistState[key] = { answer: answer, remark: (checklistState[key] && checklistState[key].remark) || '' };
        document.getElementById('startCheckBtn').disabled = !checklistIsComplete();
    });

    document.getElementById('checklistQuestions').addEventListener('input', function (e) {
        const field = e.target.closest('.item-remark');
        if (!field) return;

        const question = field.closest('.checklist-question');
        const key = question.dataset.item;
        if (!checklistState[key]) checklistState[key] = { answer: null, remark: '' };
        checklistState[key].remark = field.value.trim();

        document.getElementById('startCheckBtn').disabled = !checklistIsComplete();
    });

    document.getElementById('completion_photos').addEventListener('change', function () {
        // A native file input REPLACES its FileList on every re-pick (there's
        // no browser-level "add to selection" for repeated picker sessions),
        // so merge into the running accumulator instead of trusting this.files.
        const newFiles = Array.from(this.files);
        let skipped = 0;
        for (const file of newFiles) {
            if (completionPhotosData.length >= MAX_COMPLETION_PHOTOS) {
                skipped++;
                continue;
            }
            completionPhotosData.push(file);
        }
        if (skipped > 0) {
            showMessage('You can attach at most ' + MAX_COMPLETION_PHOTOS + ' photos — ' + skipped + ' extra selection(s) were skipped.', 'error');
        }
        rebuildCompletionPhotosInput();
    });

    const completionRemarkField = document.getElementById('completion_remark');
    completionRemarkField.addEventListener('input', function () {
        document.getElementById('completionRemarkCount').textContent = this.value.length;
    });
});

// ---------------------------------------------------------------------
// Confirm code: masked by default, toggle to reveal
// ---------------------------------------------------------------------

function toggleConfirmCodeVisibility() {
    const input = document.getElementById('confirm_code');
    const icon = document.getElementById('confirmCodeToggleIcon');
    const showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    icon.classList.toggle('fa-eye-slash', showing);
    icon.classList.toggle('fa-eye', !showing);
}

function resetConfirmCodeVisibility() {
    const input = document.getElementById('confirm_code');
    const icon = document.getElementById('confirmCodeToggleIcon');
    if (!input || !icon) return;
    input.type = 'password';
    icon.classList.add('fa-eye-slash');
    icon.classList.remove('fa-eye');
}

async function submitStartCheck() {
    if (!checklistIsComplete()) {
        showMessage('Please answer every checklist item (and add a remark for any No / N/A answer).', 'error');
        return;
    }

    const btn = document.getElementById('startCheckBtn');
    btn.disabled = true;

    const formData = new FormData();
    formData.set('csrf_token', QRS_CSRF_TOKEN);
    formData.set('task_id', QRS_TASK_ID);
    formData.set('task_location_id', document.getElementById('method_task_location_id').value);
    CHECKLIST_KEYS.forEach(function (key) {
        formData.set(key + '_answer', checklistState[key].answer);
        formData.set(key + '_remark', checklistState[key].remark || '');
    });
    formData.set('findings_observation', document.getElementById('findings_observation').value.trim());

    try {
        const response = await fetch('../api/scan/start.php', { method: 'POST', body: formData });
        const data = await response.json();

        showMessage(data.message, data.type || (data.success ? 'success' : 'error'));
        if (data.success) {
            setTimeout(() => window.location.reload(), 1000);
        } else {
            btn.disabled = false;
        }
    } catch (err) {
        showMessage('Could not reach the server. Please try again.', 'error');
        btn.disabled = false;
    }
}

// ---------------------------------------------------------------------
// Completion (after-photos)
// ---------------------------------------------------------------------

async function submitCompleteCheck() {
    const photosInput = document.getElementById('completion_photos');
    if (photosInput.files.length === 0) {
        showMessage('Please attach at least one photo before submitting.', 'error');
        return;
    }
    if (photosInput.files.length > MAX_COMPLETION_PHOTOS) {
        showMessage('Please attach at most ' + MAX_COMPLETION_PHOTOS + ' photos.', 'error');
        return;
    }

    const confirmCode = document.getElementById('confirm_code').value.trim();
    if (!confirmCode) {
        showMessage('Please confirm your Staff/Biometrics Code to complete this check.', 'error');
        return;
    }

    const btn = document.getElementById('completeCheckBtn');
    btn.disabled = true;

    const formData = new FormData();
    formData.set('csrf_token', QRS_CSRF_TOKEN);
    formData.set('task_id', QRS_TASK_ID);
    formData.set('task_location_id', document.getElementById('completion_task_location_id').value);
    formData.set('remark', document.getElementById('completion_remark').value.trim());
    formData.set('confirm_code', confirmCode);
    Array.from(photosInput.files).forEach(file => formData.append('photos[]', file));

    try {
        const response = await fetch('../api/scan/complete.php', { method: 'POST', body: formData });
        const data = await response.json();

        showMessage(data.message, data.type || (data.success ? 'success' : 'error'));
        if (data.success) {
            setTimeout(() => window.location.reload(), 1000);
        } else {
            btn.disabled = false;
        }
    } catch (err) {
        showMessage('Could not reach the server. Please try again.', 'error');
        btn.disabled = false;
    }
}

function rebuildCompletionPhotosInput() {
    const fileInput = document.getElementById('completion_photos');
    const dataTransfer = new DataTransfer();
    completionPhotosData.forEach(f => dataTransfer.items.add(f));
    fileInput.files = dataTransfer.files;
    updatePhotoPreview('completionPhotoContainer', fileInput);
    updatePhotoCountLabel();
}

function updatePhotoCountLabel() {
    const label = document.getElementById('photoCountLabel');
    if (label) {
        label.textContent = completionPhotosData.length + ' / ' + MAX_COMPLETION_PHOTOS + ' photos';
    }
}

function updatePhotoPreview(containerId, input) {
    const container = document.getElementById(containerId);
    container.innerHTML = '';

    if (input.files.length > 0) {
        const countSpan = document.createElement('span');
        countSpan.className = 'photo-count';
        countSpan.textContent = `✓ ${input.files.length} photo(s) selected`;
        container.appendChild(countSpan);

        Array.from(input.files).forEach((file, index) => {
            const reader = new FileReader();
            reader.onload = function (e) {
                const wrap = document.createElement('div');
                wrap.className = 'photo-preview-wrap';

                const img = document.createElement('img');
                img.src = e.target.result;
                img.className = 'photo-preview';
                wrap.appendChild(img);

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'photo-preview-remove';
                removeBtn.setAttribute('aria-label', 'Remove photo');
                removeBtn.innerHTML = '<i class="fas fa-times"></i>';
                removeBtn.onclick = function () { removeCompletionPhoto(index); };
                wrap.appendChild(removeBtn);

                container.appendChild(wrap);
            };
            reader.readAsDataURL(file);
        });
    }
}

function removeCompletionPhoto(index) {
    completionPhotosData.splice(index, 1);
    rebuildCompletionPhotosInput();
}

// ---------------------------------------------------------------------
// Camera capture (real getUserMedia + canvas capture, feeds into the
// completion photo input)
// ---------------------------------------------------------------------

function openPhotoCamera() {
    capturedPhotosData = [];
    document.getElementById('capturedPhotos').innerHTML = '';

    const modal = document.getElementById('photoCameraModal');
    const video = document.getElementById('photoCameraVideo');

    modal.classList.add('active');

    navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } })
        .then(stream => {
            photoStream = stream;
            video.srcObject = stream;
        })
        .catch(err => {
            showMessage("Unable to access camera: " + err.message, 'error');
            closePhotoCamera();
        });
}

function capturePhoto() {
    const video = document.getElementById('photoCameraVideo');
    const canvas = document.getElementById('photoCameraCanvas');
    const context = canvas.getContext('2d');

    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    context.drawImage(video, 0, 0);

    const remainingSlots = MAX_COMPLETION_PHOTOS - completionPhotosData.length - capturedPhotosData.length;
    if (remainingSlots <= 0) {
        showMessage('You can attach at most ' + MAX_COMPLETION_PHOTOS + ' photos total.', 'error');
        return;
    }

    canvas.toBlob(blob => {
        capturedPhotosData.push(blob);

        const img = document.createElement('img');
        img.src = URL.createObjectURL(blob);
        img.className = 'photo-preview';
        document.getElementById('capturedPhotos').appendChild(img);

        showMessage(`Photo ${capturedPhotosData.length} captured! You can take more or click Done.`, 'success');
    }, 'image/jpeg', 0.9);
}

function closePhotoCamera() {
    const modal = document.getElementById('photoCameraModal');
    modal.classList.remove('active');

    if (photoStream) {
        photoStream.getTracks().forEach(track => track.stop());
        photoStream = null;
    }

    if (capturedPhotosData.length > 0) {
        capturedPhotosData.forEach((blob, index) => {
            if (completionPhotosData.length >= MAX_COMPLETION_PHOTOS) return;
            completionPhotosData.push(new File([blob], `photo_${Date.now()}_${index}.jpg`, { type: 'image/jpeg' }));
        });
        capturedPhotosData = [];
        rebuildCompletionPhotosInput();
    }
}

// ---------------------------------------------------------------------
// QR scanning (real html5-qrcode integration) -> resolves via the server,
// never against a client-side lookup table
// ---------------------------------------------------------------------

function openQRScanner() {
    const modal = document.getElementById('qrScannerModal');
    modal.classList.add('active');

    html5QrCode = new Html5Qrcode("qr-reader");

    const qrCodeSuccessCallback = (decodedText) => {
        html5QrCode.stop()
            .then(() => closeQRScanner())
            .catch(() => closeQRScanner())
            .finally(() => resolveLocation({ qr_token: decodedText.trim() }));
    };

    const containerWidth = document.getElementById("qr-reader").offsetWidth;
    const qrBoxSize = Math.min(250, Math.floor(containerWidth * 0.8));

    const config = {
        fps: 15,
        qrbox: { width: qrBoxSize, height: qrBoxSize },
        aspectRatio: 1.0,
        disableFlip: false,
        experimentalFeatures: { useBarCodeDetectorIfSupported: true }
    };

    html5QrCode.start({ facingMode: "environment" }, config, qrCodeSuccessCallback)
        .catch(err => {
            closeQRScanner();
            showMessage("Unable to access the camera. Check camera permissions, or choose the location manually instead.", 'error');
        });
}

function closeQRScanner() {
    const modal = document.getElementById('qrScannerModal');
    modal.classList.remove('active');

    if (html5QrCode && html5QrCode.isScanning) {
        html5QrCode.stop().then(() => {
            html5QrCode.clear();
            html5QrCode = null;
        }).catch(() => { html5QrCode = null; });
    }
}
