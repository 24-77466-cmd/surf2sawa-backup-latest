const TOPUP_API = 'php/';

function updateDemoPaymentInfo() {
    const method = document.getElementById('topup-method')?.value || 'gcash';
    const info = document.getElementById('demo-payment-info');
    if (!info) return;
    info.textContent = method === 'gcash'
        ? 'GCash simulation: this creates a pending demo request for admin review. No GCash app opens, no QR code is charged, and no money moves.'
        : 'Bank transfer simulation: this creates a pending demo request for admin review. No bank account details or real transfer are involved.';
}

function showTopupMessage(message, ok = true) {
    const box = document.getElementById('topup-msg');
    if (!box) return;
    box.textContent = message || '';
    box.style.display = message ? 'block' : 'none';
    box.style.border = ok ? '1px solid var(--load-normal)' : '1px solid var(--accent-2)';
    box.style.color = ok ? 'var(--load-normal)' : '#ff7a6b';
    box.style.background = ok ? 'rgba(34,197,94,.12)' : 'rgba(255,92,138,.12)';
}

async function loadTopupHistory() {
    const body = document.getElementById('topup-history');
    if (!body) return;
    try {
        const res = await fetch(TOPUP_API + 'get_topup_requests.php', { cache: 'no-store' });
        const data = await res.json();
        if (!data.success) {
            body.innerHTML = `<tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:20px">Log in to view top-up requests.</td></tr>`;
            return;
        }
        body.innerHTML = data.requests.map(r => {
            const status = ['pending', 'approved', 'rejected'].includes(r.status) ? r.status : 'pending';
            const method = r.payment_method === 'gcash' ? 'GCash (demo)' : 'Bank (demo)';
            return `<tr>
                <td>${esc(r.reference)}</td>
                <td>₱${Number(r.amount).toFixed(2)}</td>
                <td>${method}</td>
                <td><span class="status-pill status-${status}">${esc(status)}</span></td>
                <td>${esc(r.created_at)}</td>
            </tr>`;
        }).join('') || `<tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:20px">No top-up requests yet.</td></tr>`;
    } catch (e) {
        body.innerHTML = `<tr><td colspan="5" style="text-align:center;color:#ff7a6b;padding:20px">Could not load request history.</td></tr>`;
    }
}

async function submitDemoTopup(event) {
    event.preventDefault();
    const amount = document.getElementById('topup-amount').value;
    const method = document.getElementById('topup-method').value;
    const button = document.getElementById('topup-submit');
    button.disabled = true;
    button.textContent = 'Submitting…';
    showTopupMessage('', true);
    try {
        const res = await fetch(TOPUP_API + 'create_topup_request.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ amount, method })
        });
        const data = await res.json();
        if (!data.success) {
            showTopupMessage(data.message || 'Could not submit the request.', false);
            return;
        }
        showTopupMessage(`${data.message} Reference: ${data.request.reference}`, true);
        await loadTopupHistory();
    } catch (e) {
        showTopupMessage('Request failed. Check the server connection and try again.', false);
    } finally {
        button.disabled = false;
        button.textContent = 'Submit demo request';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const method = document.getElementById('topup-method');
    if (method) method.addEventListener('change', updateDemoPaymentInfo);
    document.querySelectorAll('[data-amount]').forEach(button => {
        button.addEventListener('click', () => {
            document.getElementById('topup-amount').value = button.dataset.amount;
        });
    });
    const form = document.getElementById('topup-form');
    if (form) form.addEventListener('submit', submitDemoTopup);
});
