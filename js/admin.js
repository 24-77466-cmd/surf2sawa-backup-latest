const API = "php/";
const charts = {};
let currentAdminId = null;

// Caches to avoid inline JSON in onclick handlers
const cachedUsers = new Map();
const cachedPlans = new Map();

/* ---------- XSS SANITIZER ---------- */
function esc(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/* ---------- SESSION GUARD ---------- */
async function guard() {
    try {
        const res = await fetch(API + "admin_check.php");
        const data = await res.json();
        if (!data.success) {
            alert("Admin access required. Please log in.");
            window.location.href = "login.html";
            return false;
        }
        if (data.user) {
            currentAdminId = data.user.id;
            const adminNameEl = document.getElementById('admin-name');
            if (adminNameEl) adminNameEl.textContent = data.user.fullname || 'Admin';
        }
        return true;
    } catch (e) {
        alert("Authentication check failed. Please check server.");
        window.location.href = "login.html";
        return false;
    }
}

/* ---------- TABS & ROUTING ---------- */
function switchTab(tabName) {
    const btn = document.querySelector(`.nav-item[data-tab="${tabName}"]`);
    const targetSection = document.getElementById('tab-' + tabName);
    if (!btn || !targetSection) return;

    document.querySelectorAll('.nav-item').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    document.querySelectorAll('.tab').forEach(t => t.hidden = true);
    targetSection.hidden = false;

    window.location.hash = tabName;

    if (tabName === 'overview')  loadStats();
    if (tabName === 'analytics') loadCharts();
    if (tabName === 'users')     loadUsers(1);
    if (tabName === 'plans')     loadPlans(1);
    if (tabName === 'purchases')  loadPurchases(1);
    if (tabName === 'topups')     loadTopupRequests();
    if (tabName === 'logs')       loadLogs(1);
}

document.querySelectorAll('.nav-item').forEach(btn => {
    btn.onclick = () => switchTab(btn.dataset.tab);
});

/* ---------- TOAST ---------- */
function toast(msg, ok = true) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.style.background = ok ? 'var(--accent)' : 'var(--accent-2)';
    t.style.color = ok ? 'var(--on-accent)' : '#fff';
    t.style.display = 'block';
    clearTimeout(toast.t);
    toast.t = setTimeout(() => { t.style.display = 'none'; }, 2600);
}

/* ---------- STATS (OVERVIEW) ---------- */
async function loadStats() {
    try {
        const res = await fetch(API + "admin_stats.php");
        const data = await res.json();
        if (!data.success) return;
        const s = data.stats;
        document.getElementById('s-users').textContent     = Number(s.total_users).toLocaleString();
        document.getElementById('s-plans').textContent     = Number(s.total_plans).toLocaleString();
        document.getElementById('s-purchases').textContent = Number(s.total_purchases).toLocaleString();
        document.getElementById('s-active').textContent    = Number(s.active_subs).toLocaleString();
        document.getElementById('s-revenue').textContent   = '₱' + parseFloat(s.total_revenue || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    } catch (err) {
        console.error('Stats error:', err);
    }
}

/* ---------- CHARTS (ANALYTICS) ---------- */
async function loadCharts() {
    try {
        const res = await fetch(API + "admin_charts.php");
        const data = await res.json();
        if (!data.success) { toast('Failed to load chart data', false); return; }

        const css = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
        const teal   = css('--accent') || '#00e0ff';
        const pink   = css('--accent-2') || '#ff5c8a';
        const green  = css('--load-normal') || '#22c55e';
        const yellow = css('--load-elevated') || '#e0a010';
        const orange = css('--load-high') || '#f08a3c';
        const red    = css('--load-critical') || '#d9402f';
        const grid   = css('--border') || '#1e2a52';
        const tick   = css('--text-muted') || '#a9b3d6';

        const baseOpts = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { labels: { color: tick, font: { family: 'Segoe UI', size: 12 } } },
                tooltip: { padding: 10, cornerRadius: 8 }
            },
            scales: {
                x: { ticks: { color: tick, maxRotation: 45 }, grid: { color: grid } },
                y: { ticks: { color: tick }, grid: { color: grid }, beginAtZero: true }
            }
        };

        // 1. Revenue
        if (charts.revenue) charts.revenue.destroy();
        charts.revenue = new Chart(document.getElementById('chart-revenue'), {
            type: 'line',
            data: {
                labels: (data.revenue || []).map(r => r.date.slice(5)),
                datasets: [{
                    label: 'Revenue (₱)',
                    data: (data.revenue || []).map(r => r.total),
                    borderColor: teal,
                    backgroundColor: teal + '22',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: baseOpts
        });

        // 2. Counts
        if (charts.counts) charts.counts.destroy();
        charts.counts = new Chart(document.getElementById('chart-counts'), {
            type: 'bar',
            data: {
                labels: (data.counts || []).map(r => r.date.slice(5)),
                datasets: [{
                    label: 'Purchases',
                    data: (data.counts || []).map(r => r.count),
                    backgroundColor: green,
                    borderRadius: 6
                }]
            },
            options: baseOpts
        });

        // 3. Top Plans
        if (charts.plans) charts.plans.destroy();
        const planLabels = (data.plans || []).map(p => p.name);
        const planData   = (data.plans || []).map(p => p.count);
        charts.plans = new Chart(document.getElementById('chart-plans'), {
            type: 'doughnut',
            data: {
                labels: planLabels.length ? planLabels : ['No purchases yet'],
                datasets: [{
                    data: planData.length ? planData : [1],
                    backgroundColor: planData.length ? [teal, green, yellow, orange, pink] : [grid]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: tick } } }
            }
        });

        // 4. User Growth
        if (charts.users) charts.users.destroy();
        charts.users = new Chart(document.getElementById('chart-users'), {
            type: 'line',
            data: {
                labels: (data.users || []).map(r => r.date.slice(5)),
                datasets: [{
                    label: 'New Registrations',
                    data: (data.users || []).map(r => r.count),
                    borderColor: pink,
                    backgroundColor: pink + '22',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: baseOpts
        });

        // 5. Customer Status
        const st = data.status || {};
        if (charts.status) charts.status.destroy();
        charts.status = new Chart(document.getElementById('chart-status'), {
            type: 'pie',
            data: {
                labels: ['Active', 'Pending', 'Suspended', 'Unavailable'],
                datasets: [{
                    data: [+st.active || 0, +st.pending || 0, +st.suspended || 0, +st.unavailable || 0],
                    backgroundColor: [green, yellow, orange, red]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: tick } } }
            }
        });
    } catch (err) {
        console.error('Charts error:', err);
    }
}

/* ---------- PAGER RENDERER ---------- */
function renderPager(el, page, pages, onGo) {
    const box = document.getElementById(el);
    if (!box) return;
    if (pages <= 1) { box.innerHTML = ''; return; }

    let html = `<button ${page === 1 ? 'disabled' : ''} onclick="${onGo}(${page - 1})">‹ Prev</button>`;
    const start = Math.max(1, page - 2);
    const end   = Math.min(pages, start + 4);
    for (let i = start; i <= end; i++) {
        html += `<button class="${i === page ? 'active' : ''}" onclick="${onGo}(${i})">${i}</button>`;
    }
    html += `<button ${page === pages ? 'disabled' : ''} onclick="${onGo}(${page + 1})">Next ›</button>`;
    html += `<div class="pager-info">Page ${page} of ${pages}</div>`;
    box.innerHTML = html;
}

/* ---------- USERS ---------- */
let usersTimer;
function debounceUsers() { clearTimeout(usersTimer); usersTimer = setTimeout(() => loadUsers(1), 300); }

async function loadUsers(page = 1) {
    try {
        const q = document.getElementById('users-q').value.trim();
        const res = await fetch(`${API}admin_users.php?action=list&page=${page}&q=${encodeURIComponent(q)}`);
        const data = await res.json();
        if (!data.success) return;

        cachedUsers.clear();
        data.users.forEach(u => cachedUsers.set(u.id, u));

        const tb = document.getElementById('users-tbody');
        tb.innerHTML = data.users.map(u => {
            const isSelf = currentAdminId !== null && Number(u.id) === Number(currentAdminId);
            return `
            <tr>
                <td>${esc(u.id)}</td>
                <td><strong>${esc(u.fullname)}</strong> ${isSelf ? '<span class="badge active" style="font-size:0.7rem">You</span>' : ''}</td>
                <td>${esc(u.email)}</td>
                <td><span class="badge ${esc(u.role)}">${esc(u.role)}</span></td>
                <td>₱${parseFloat(u.balance || 0).toFixed(2)}</td>
                <td>${esc((u.created_at || '').slice(0, 10))}</td>
                <td>
                    <button class="btn-primary btn-sm" onclick="editUserById(${u.id})">Edit</button>
                    <button class="btn-outline btn-sm" onclick="topupUserById(${u.id})">Top-up</button>
                    ${!isSelf ? `<button class="btn-outline btn-sm" style="color:#ff7a6b;border-color:rgba(217,64,47,0.4)" onclick="deleteUser(${u.id})">Delete</button>` : ''}
                </td>
            </tr>`;
        }).join('') || `<tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:24px">No users found.</td></tr>`;

        renderPager('users-pager', data.page, data.pages, 'loadUsers');
    } catch (err) {
        console.error('Users load error:', err);
    }
}

function openUserModal() {
    document.getElementById('user-modal-title').textContent = 'Add User';
    document.getElementById('u-id').value = '';
    ['u-name', 'u-email', 'u-pass', 'u-addr'].forEach(i => {
        const el = document.getElementById(i);
        if (el) el.value = '';
    });
    const locBox = document.getElementById('u-loc-box');
    if (locBox) locBox.style.display = 'block';

    initAdminPSGC();

    document.getElementById('u-role').value = 'user';
    document.getElementById('u-bal').value = 0;
    document.getElementById('user-modal').classList.add('open');
    document.getElementById('u-name').focus();
}

/* Admin PSGC cascading helper */
let adminPsgcInit = false;
async function initAdminPSGC() {
    const regSel = document.getElementById('u-reg');
    const provSel = document.getElementById('u-prov');
    const citySel = document.getElementById('u-city');
    const brgySel = document.getElementById('u-brgy');
    if (!regSel || !provSel || !citySel || !brgySel) return;

    if (!adminPsgcInit) {
        adminPsgcInit = true;

        regSel.addEventListener('change', async () => {
            provSel.innerHTML = '<option value="">-- Select Province --</option>';
            provSel.disabled = true;
            citySel.innerHTML = '<option value="">-- Select City / Municipality --</option>';
            citySel.disabled = true;
            brgySel.innerHTML = '<option value="">-- Select Barangay --</option>';
            brgySel.disabled = true;
            if (!regSel.value) return;

            try {
                provSel.innerHTML = '<option value="">Loading Provinces…</option>';
                const res = await fetch(`php/get_psgc.php?type=provinces&region=${regSel.value}`);
                const provs = await res.json();
                if (Array.isArray(provs) && provs.length > 0) {
                    provs.sort((a,b) => a.name.localeCompare(b.name));
                    provSel.innerHTML = '<option value="">-- Select Province --</option>' +
                        provs.map(p => `<option value="${p.code}" data-name="${esc(p.name)}">${esc(p.name)}</option>`).join('');
                    provSel.disabled = false;
                } else {
                    const rName = regSel.options[regSel.selectedIndex]?.dataset.name || regSel.value;
                    provSel.innerHTML = `<option value="ncr_direct" data-name="${esc(rName)}">${esc(rName)}</option>`;
                    provSel.disabled = false;

                    citySel.innerHTML = '<option value="">Loading Cities…</option>';
                    const cRes = await fetch(`php/get_psgc.php?type=citymuns&region=${regSel.value}`);
                    const cities = await cRes.json();
                    cities.sort((a,b) => a.name.localeCompare(b.name));
                    citySel.innerHTML = '<option value="">-- Select City / Municipality --</option>' +
                        cities.map(c => `<option value="${c.code}" data-name="${esc(c.name)}">${esc(c.name)}</option>`).join('');
                    citySel.disabled = false;
                }
            } catch (e) {
                provSel.innerHTML = '<option value="">Failed to load provinces</option>';
            }
        });

        provSel.addEventListener('change', async () => {
            if (provSel.value === 'ncr_direct') return;
            citySel.innerHTML = '<option value="">-- Select City / Municipality --</option>';
            citySel.disabled = true;
            brgySel.innerHTML = '<option value="">-- Select Barangay --</option>';
            brgySel.disabled = true;
            if (!provSel.value) return;

            try {
                citySel.innerHTML = '<option value="">Loading Cities…</option>';
                const res = await fetch(`php/get_psgc.php?type=citymuns&province=${provSel.value}`);
                const cities = await res.json();
                cities.sort((a,b) => a.name.localeCompare(b.name));
                citySel.innerHTML = '<option value="">-- Select City / Municipality --</option>' +
                    cities.map(c => `<option value="${c.code}" data-name="${esc(c.name)}">${esc(c.name)}</option>`).join('');
                citySel.disabled = false;
            } catch (e) {
                citySel.innerHTML = '<option value="">Failed to load cities</option>';
            }
        });

        citySel.addEventListener('change', async () => {
            brgySel.innerHTML = '<option value="">-- Select Barangay --</option>';
            brgySel.disabled = true;
            if (!citySel.value) return;

            try {
                brgySel.innerHTML = '<option value="">Loading Barangays…</option>';
                let brgys;
                try {
                    const r = await fetch(`php/get_psgc.php?type=barangays&city=${citySel.value}`);
                    brgys = await r.json();
                } catch { brgys = null; }
                if (!brgys || !brgys.length) {
                    const r = await fetch(`php/get_psgc.php?type=barangays&mun=${citySel.value}`);
                    brgys = await r.json();
                }
                brgys.sort((a,b) => a.name.localeCompare(b.name));
                brgySel.innerHTML = '<option value="">-- Select Barangay --</option>' +
                    brgys.map(b => `<option value="${b.code}" data-name="${esc(b.name)}">${esc(b.name)}</option>`).join('');
                brgySel.disabled = false;
            } catch (e) {
                brgySel.innerHTML = '<option value="">Failed to load barangays</option>';
            }
        });
    }

    if (regSel.options.length <= 1) {
        try {
            regSel.innerHTML = '<option value="">Loading Regions…</option>';
            const r = await fetch('php/get_psgc.php?type=regions');
            const regs = await r.json();
            regs.sort((a,b) => a.name.localeCompare(b.name));
            regSel.innerHTML = '<option value="">-- Select Region --</option>' +
                regs.map(x => `<option value="${x.code}" data-name="${esc(x.name)}">${esc(x.name)}</option>`).join('');
        } catch (e) {
            regSel.innerHTML = '<option value="">Failed to load regions</option>';
        }
    }
}

function editUserById(id) {
    const u = cachedUsers.get(id);
    if (!u) return;
    document.getElementById('user-modal-title').textContent = 'Edit User #' + u.id;
    document.getElementById('u-id').value    = u.id;
    document.getElementById('u-name').value  = u.fullname || '';
    document.getElementById('u-email').value = u.email || '';
    document.getElementById('u-pass').value  = '';
    document.getElementById('u-role').value  = u.role || 'user';
    document.getElementById('u-bal').value   = parseFloat(u.balance || 0).toFixed(2);

    const locBox = document.getElementById('u-loc-box');
    if (locBox) locBox.style.display = 'none';

    document.getElementById('user-modal').classList.add('open');
    document.getElementById('u-name').focus();
}

async function saveUser() {
    const id = document.getElementById('u-id').value;
    const fullname = document.getElementById('u-name').value.trim();
    const email    = document.getElementById('u-email').value.trim();
    const password = document.getElementById('u-pass').value;
    const role     = document.getElementById('u-role').value;
    const balance  = parseFloat(document.getElementById('u-bal').value) || 0;
    const address  = document.getElementById('u-addr') ? document.getElementById('u-addr').value.trim() : '';
    const regEl    = document.getElementById('u-reg');
    const provEl   = document.getElementById('u-prov');
    const cityEl   = document.getElementById('u-city');
    const brgyEl   = document.getElementById('u-brgy');

    const region   = regEl ? (regEl.options[regEl.selectedIndex]?.dataset.name || regEl.value) : '';
    let   province = provEl ? (provEl.options[provEl.selectedIndex]?.dataset.name || provEl.value) : '';
    if (provEl && provEl.value === 'ncr_direct') province = 'Metro Manila';
    const city     = cityEl ? (cityEl.options[cityEl.selectedIndex]?.dataset.name || cityEl.value) : '';
    const barangay = brgyEl ? (brgyEl.options[brgyEl.selectedIndex]?.dataset.name || brgyEl.value) : '';
    const brgyCode = brgyEl ? brgyEl.value : '';

    if (!fullname || !email) {
        toast('Name and email are required', false);
        return;
    }
    if (!id && !password) {
        toast('Password is required for new users', false);
        return;
    }
    if (password && password.length < 6) {
        toast('Password must be at least 6 characters', false);
        return;
    }

    const payload = {
        id, fullname, email, password, role, balance, address,
        barangay,
        region,
        province,
        city,
        barangay_name: barangay,
        barangay_code: brgyCode
    };
    const action = id ? 'edit' : 'add';

    try {
        const res = await fetch(`${API}admin_users.php?action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        toast(data.message, data.success);
        if (data.success) {
            closeModal('user-modal');
            loadUsers(1);
        }
    } catch (e) {
        toast('Request failed', false);
    }
}

async function deleteUser(id) {
    if (!confirm('Are you sure you want to delete this user? This cannot be undone.')) return;
    try {
        const res = await fetch(API + 'admin_users.php?action=delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        const data = await res.json();
        toast(data.message, data.success);
        if (data.success) loadUsers(1);
    } catch (e) {
        toast('Delete request failed', false);
    }
}

/* ---------- TOP-UP ---------- */
function topupUserById(id) {
    const u = cachedUsers.get(id);
    document.getElementById('t-user-id').value = id;
    document.getElementById('t-user-name').textContent = 'User: ' + (u ? u.fullname + ' (' + u.email + ')' : '#' + id);
    document.getElementById('t-amount').value = 100;
    document.getElementById('topup-modal').classList.add('open');
    document.getElementById('t-amount').focus();
}

async function doTopup() {
    const user_id = parseInt(document.getElementById('t-user-id').value, 10);
    const amount  = parseFloat(document.getElementById('t-amount').value);

    if (!user_id || isNaN(amount) || amount <= 0) {
        toast('Please enter a valid amount', false);
        return;
    }

    try {
        const res = await fetch(API + 'admin_topup.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id, amount })
        });
        const data = await res.json();
        toast(data.message, data.success);
        if (data.success) {
            closeModal('topup-modal');
            loadUsers(1);
        }
    } catch (e) {
        toast('Top-up failed', false);
    }
}

/* ---------- PLANS ---------- */
let plansTimer;
let planImagePath = ''; // picture currently attached to the plan being edited
function debouncePlans() { clearTimeout(plansTimer); plansTimer = setTimeout(() => loadPlans(1), 300); }

async function loadPlans(page = 1) {
    try {
        const q = document.getElementById('plans-q').value.trim();
        const res = await fetch(`${API}admin_plans.php?action=list&page=${page}&q=${encodeURIComponent(q)}`);
        const data = await res.json();
        if (!data.success) return;

        cachedPlans.clear();
        data.plans.forEach(p => cachedPlans.set(p.id, p));

        const tb = document.getElementById('plans-tbody');
        tb.innerHTML = data.plans.map(p => `
            <tr>
                <td>${esc(p.id)}</td>
                <td><b>${esc(p.name)}</b></td>
                <td>${esc(p.description || '')}</td>
                <td>₱${parseFloat(p.price).toFixed(2)}</td>
                <td>${esc(p.duration_hours)}h</td>
                <td>
                    <button class="btn-primary btn-sm" onclick="editPlanById(${p.id})">Edit</button>
                    <button class="btn-outline btn-sm" style="color:#ff7a6b;border-color:rgba(217,64,47,0.4)" onclick="deletePlan(${p.id})">Delete</button>
                </td>
            </tr>`).join('') || `<tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:24px">No plans found.</td></tr>`;

        renderPager('plans-pager', data.page, data.pages, 'loadPlans');
    } catch (e) {
        console.error('Plans error:', e);
    }
}

function setPlanImagePreview(src) {
    const preview = document.getElementById('p-image-preview');
    const removeBtn = document.getElementById('p-image-remove');
    if (src) {
        preview.src = src;
        preview.hidden = false;
        removeBtn.hidden = false;
    } else {
        preview.hidden = true;
        preview.removeAttribute('src');
        removeBtn.hidden = true;
    }
}

function removePlanImage() {
    planImagePath = '';
    document.getElementById('p-image-file').value = '';
    setPlanImagePreview('');
}

// Show a local preview as soon as a file is chosen; upload happens on save.
document.getElementById('p-image-file').addEventListener('change', () => {
    const file = document.getElementById('p-image-file').files[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) {
        toast('The picture is too large. Maximum size is 2 MB.', false);
        document.getElementById('p-image-file').value = '';
        return;
    }
    setPlanImagePreview(URL.createObjectURL(file));
});

function openPlanModal() {
    document.getElementById('plan-modal-title').textContent = 'Add Plan';
    document.getElementById('p-id').value = '';
    ['p-name', 'p-desc', 'p-price'].forEach(i => document.getElementById(i).value = '');
    document.getElementById('p-hrs').value = 1;
    removePlanImage();
    document.getElementById('plan-modal').classList.add('open');
    document.getElementById('p-name').focus();
}

function editPlanById(id) {
    const p = cachedPlans.get(id);
    if (!p) return;
    document.getElementById('plan-modal-title').textContent = 'Edit Plan #' + p.id;
    document.getElementById('p-id').value    = p.id;
    document.getElementById('p-name').value  = p.name || '';
    document.getElementById('p-desc').value  = p.description || '';
    document.getElementById('p-price').value = p.price;
    document.getElementById('p-hrs').value   = p.duration_hours;
    planImagePath = p.image || '';
    document.getElementById('p-image-file').value = '';
    setPlanImagePreview(planImagePath);
    document.getElementById('plan-modal').classList.add('open');
    document.getElementById('p-name').focus();
}

async function savePlan() {
    const id = document.getElementById('p-id').value;
    const name = document.getElementById('p-name').value.trim();
    const description = document.getElementById('p-desc').value.trim();
    const price = parseFloat(document.getElementById('p-price').value);
    const duration_hours = parseInt(document.getElementById('p-hrs').value, 10);

    if (!name) {
        toast('Plan name is required', false);
        return;
    }
    if (isNaN(price) || price <= 0) {
        toast('Price must be greater than ₱0.00', false);
        return;
    }
    if (isNaN(duration_hours) || duration_hours < 1) {
        toast('Duration must be at least 1 hour', false);
        return;
    }

    const payload = { id, name, description, price, duration_hours };
    const action = id ? 'edit' : 'add';

    try {
        // Upload the chosen picture first (admin-only endpoint), then save its path.
        const fileInput = document.getElementById('p-image-file');
        let image = planImagePath;
        if (fileInput.files && fileInput.files[0]) {
            const form = new FormData();
            form.append('image', fileInput.files[0]);
            const up = await fetch(`${API}admin_upload_plan_image.php`, { method: 'POST', body: form });
            const upData = await up.json();
            if (!upData.success) {
                toast(upData.message || 'Picture upload failed', false);
                return;
            }
            image = upData.image;
        }
        payload.image = image;

        const res = await fetch(`${API}admin_plans.php?action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        toast(data.message, data.success);
        if (data.success) {
            closeModal('plan-modal');
            loadPlans(1);
        }
    } catch (e) {
        toast('Save plan failed', false);
    }
}

async function deletePlan(id) {
    if (!confirm('Are you sure you want to delete this plan?')) return;
    try {
        const res = await fetch(API + 'admin_plans.php?action=delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        const data = await res.json();
        toast(data.message, data.success);
        if (data.success) loadPlans(1);
    } catch (e) {
        toast('Delete plan failed', false);
    }
}

/* ---------- PURCHASES ---------- */
let purchasesTimer;
function debouncePurchases() { clearTimeout(purchasesTimer); purchasesTimer = setTimeout(() => loadPurchases(1), 300); }

async function loadPurchases(page = 1) {
    try {
        const q = document.getElementById('purchases-q').value.trim();
        const res = await fetch(`${API}admin_purchases.php?page=${page}&q=${encodeURIComponent(q)}`);
        const data = await res.json();
        if (!data.success) return;

        const tb = document.getElementById('purchases-tbody');
        tb.innerHTML = data.purchases.map(p => `
            <tr>
                <td>${esc(p.id)}</td>
                <td><b>${esc(p.fullname)}</b><br><small style="color:var(--text-muted)">${esc(p.email)}</small></td>
                <td>${esc(p.plan_name)}</td>
                <td>₱${parseFloat(p.price).toFixed(2)}</td>
                <td style="white-space:nowrap">${esc(p.purchased_at)}</td>
                <td style="white-space:nowrap">${esc(p.expires_at)}</td>
                <td><span class="badge ${esc(p.status)}">${esc(p.status)}</span></td>
            </tr>`).join('') || `<tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:24px">No purchases found.</td></tr>`;

        renderPager('purchases-pager', data.page, data.pages, 'loadPurchases');
    } catch (e) {
        console.error('Purchases error:', e);
    }
}

/* ---------- DEMO TOP-UP REQUESTS ---------- */
async function loadTopupRequests() {
    const tbody = document.getElementById('topups-tbody');
    if (!tbody) return;
    tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:20px">Loading demo requests…</td></tr>`;
    try {
        const res = await fetch(API + 'admin_topup_requests.php', { cache: 'no-store' });
        const data = await res.json();
        if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;color:#ff7a6b;padding:20px">Could not load requests.</td></tr>`;
            return;
        }
        tbody.innerHTML = data.requests.map(r => {
            const status = ['pending', 'approved', 'rejected'].includes(r.status) ? r.status : 'pending';
            const method = r.payment_method === 'gcash' ? 'GCash (demo)' : 'Bank (demo)';
            const actions = status === 'pending'
                ? `<button class="btn-primary btn-sm" onclick="reviewDemoTopup(${Number(r.id)}, 'approve')">Approve demo credit</button>
                   <button class="btn-outline btn-sm" onclick="reviewDemoTopup(${Number(r.id)}, 'reject')">Reject</button>`
                : '—';
            const reviewer = r.reviewed_at ? `${esc(r.reviewed_at)}${r.reviewer_name ? '<br><small>' + esc(r.reviewer_name) + '</small>' : ''}` : '—';
            return `<tr>
                <td><strong>${esc(r.reference)}</strong></td>
                <td><strong>${esc(r.fullname)}</strong><br><small>${esc(r.email)}</small></td>
                <td>₱${Number(r.amount).toFixed(2)}</td>
                <td>${method}</td>
                <td><span class="badge ${status === 'approved' ? 'active' : status === 'rejected' ? 'expired' : 'user'}">${esc(status)}</span></td>
                <td style="white-space:nowrap">${esc(r.created_at)}</td>
                <td style="white-space:nowrap">${reviewer}</td>
                <td style="min-width:190px"><div style="display:flex;gap:6px;flex-wrap:wrap">${actions}</div></td>
            </tr>`;
        }).join('') || `<tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:24px">No demo top-up requests yet.</td></tr>`;
    } catch (e) {
        console.error('Demo top-up requests error:', e);
        tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;color:#ff7a6b;padding:20px">Could not load requests.</td></tr>`;
    }
}

async function reviewDemoTopup(requestId, decision) {
    if (!Number.isInteger(requestId) || !['approve', 'reject'].includes(decision)) return;
    const prompt = decision === 'approve'
        ? 'Approve this simulated request? This will add demo credit to the user balance; no real payment is verified.'
        : 'Reject this simulated request? No balance will be added.';
    if (!window.confirm(prompt)) return;
    try {
        const res = await fetch(API + 'admin_review_topup.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ request_id: requestId, decision })
        });
        const data = await res.json();
        toast(data.message, data.success);
        if (data.success) loadTopupRequests();
    } catch (e) {
        toast('Could not review demo request.', false);
    }
}

/* ---------- LOGS ---------- */
let logsTimer;
function debounceLogs() { clearTimeout(logsTimer); logsTimer = setTimeout(() => loadLogs(1), 300); }

async function loadLogs(page = 1) {
    try {
        const q = document.getElementById('logs-q').value.trim();
        const res = await fetch(`${API}admin_logs.php?page=${page}&q=${encodeURIComponent(q)}`);
        const data = await res.json();
        if (!data.success) return;

        const tb = document.getElementById('logs-tbody');
        tb.innerHTML = data.logs.map(l => `
            <tr>
                <td style="white-space:nowrap">${esc(l.created_at)}</td>
                <td><b>${esc(l.admin_name || '—')}</b></td>
                <td><span class="badge user">${esc(l.action)}</span></td>
                <td>${esc(l.target || '—')}</td>
                <td>${esc(l.detail || '—')}</td>
                <td style="color:var(--text-muted);font-size:0.8rem">${esc(l.ip || '—')}</td>
            </tr>`).join('') || `<tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:24px">No logs yet.</td></tr>`;

        renderPager('logs-pager', data.page, data.pages, 'loadLogs');
    } catch (e) {
        console.error('Logs error:', e);
    }
}

/* ---------- CSV EXPORT ---------- */
function exportCSV(type) {
    window.location.href = `${API}admin_export.php?type=${encodeURIComponent(type)}`;
    toast('Downloading ' + type + '.csv...');
}

/* ---------- MODALS ---------- */
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}

document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});

window.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal.open').forEach(m => m.classList.remove('open'));
    }
});

/* ---------- INITIALIZATION ---------- */
(async () => {
    if (await guard()) {
        const hash = window.location.hash.replace('#', '');
        const validTabs = ['overview', 'analytics', 'users', 'plans', 'purchases', 'topups', 'logs', 'locations'];
        if (validTabs.includes(hash)) {
            switchTab(hash);
        } else {
            loadStats();
        }
    }
})();

// Re-render analytics charts on theme toggle
window.addEventListener('themechange', () => {
    const analyticsTab = document.getElementById('tab-analytics');
    if (analyticsTab && !analyticsTab.hidden) {
        loadCharts();
    }
});
