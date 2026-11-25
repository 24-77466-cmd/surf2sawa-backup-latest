const API = "php/";

/* Helper: Escape HTML to prevent XSS */
function esc(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/* Helper: Notification banner or alert */
function notify(msg, ok = true) {
    const banner = document.getElementById("auth-msg");
    if (banner) {
        banner.textContent = msg;
        banner.style.display = "block";
        banner.style.background = ok ? "rgba(34, 197, 94, 0.15)" : "rgba(255, 92, 138, 0.15)";
        banner.style.border = ok ? "1px solid var(--load-normal)" : "1px solid var(--accent-2)";
        banner.style.color = ok ? "var(--load-normal)" : "#ff7a6b";
        banner.style.padding = "10px 14px";
        banner.style.borderRadius = "8px";
        banner.style.marginBottom = "14px";
        banner.style.fontSize = "0.9rem";
    } else {
        alert(msg);
    }
}

/* Register */
async function registerUser() {
    const fullname = document.getElementById("fullname").value.trim();
    const email    = document.getElementById("email").value.trim();
    const password = document.getElementById("password").value;
    const address  = document.getElementById("address") ? document.getElementById("address").value.trim() : "";
    const barangay = document.getElementById("barangay") ? document.getElementById("barangay").value : "";

    if (!fullname || !email || !password) {
        notify("Please fill in all account fields.", false);
        return;
    }
    if (document.getElementById("address") && (!address || !barangay)) {
        notify("Please provide your service street address and select your barangay.", false);
        return;
    }
    if (password.length < 6) {
        notify("Password must be at least 6 characters.", false);
        return;
    }

    try {
        const res = await fetch(API + "register.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ fullname, email, password, address, barangay })
        });
        const data = await res.json();
        notify(data.message, data.success);
        if (data.success) {
            setTimeout(() => { window.location.href = "login.html"; }, 1500);
        }
    } catch (err) {
        notify("Registration request failed. Check server connection.", false);
    }
}

/* Show/hide a password field (login) — eye icon inside the input */
function togglePassword(inputId, btn) {
    const input = document.getElementById(inputId);
    const show = input.type === "password";
    input.type = show ? "text" : "password";
    btn.classList.toggle("revealed", show);
    btn.setAttribute("aria-label", show ? "Hide password" : "Show password");
    btn.setAttribute("aria-pressed", String(show));
    btn.title = show ? "Hide password" : "Show password";
    input.focus();
}

/* Login */
async function loginUser() {
    const email    = document.getElementById("email").value.trim();
    const password = document.getElementById("password").value;

    if (!email || !password) {
        notify("Please enter your email and password.", false);
        return;
    }

    try {
        const res = await fetch(API + "login.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ email, password })
        });
        const data = await res.json();
        notify(data.message, data.success);
        if (data.success && (data.totp_required || data.totp_setup_required)) {
            document.getElementById("login-form").hidden = true;
            document.getElementById("login-otp-panel").hidden = false;
            document.getElementById("back-to-login").hidden = false;
            showLoginOtpMessage(data);
            document.getElementById("login-otp-code").focus();
            return;
        }
        if (data.success) {
            completeLogin(data.user);
        }
    } catch (err) {
        notify("Login request failed. Check server connection.", false);
    }
}

/* Store the session snapshot and redirect after a successful login */
function completeLogin(user) {
    localStorage.setItem("user", JSON.stringify(user));
    setTimeout(() => {
        window.location.href = user.role === "admin" ? "admin.html" : "dashboard.html";
    }, 600);
}

/* Render an otpauth:// URI as a QR code (generated locally, secret never leaves the browser) */
function renderAuthenticatorQr(containerId, otpauthUri) {
    const el = document.getElementById(containerId);
    if (!el || !otpauthUri || typeof QRCode === "undefined") return;
    el.innerHTML = "";
    new QRCode(el, { text: otpauthUri, width: 180, height: 180, correctLevel: QRCode.CorrectLevel.M });
}

/* Login Google Authenticator step */
function showLoginOtpMessage(data) {
    const setupBox = document.getElementById("login-totp-setup");
    if (data.totp_setup_required && data.otpauth_uri) {
        setupBox.hidden = false;
        renderAuthenticatorQr("login-qr", data.otpauth_uri);
    } else {
        setupBox.hidden = true;
    }
    document.getElementById("login-otp-copy").textContent = data.message || "Enter the 6-digit code from your Google Authenticator app.";
}

async function verifyLoginCode() {
    const code = document.getElementById("login-otp-code").value.trim();
    if (!/^\d{6}$/.test(code)) {
        notify("Enter the 6-digit code from your authenticator app.", false); return;
    }
    const btn = document.getElementById("login-verify-btn");
    btn.disabled = true; btn.textContent = "Verifying…";
    try {
        const res = await fetch(API + "verify_login_otp.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ code })
        });
        const data = await res.json();
        notify(data.message, data.success);
        if (data.success) {
            completeLogin(data.user);
        }
    } catch (err) {
        notify("Verification request failed. Check the server connection.", false);
    } finally {
        btn.disabled = false; btn.textContent = "Verify and Login";
    }
}

/* Return from the authenticator step to the email/password form */
function backToLogin() {
    fetch(API + "login_otp_cancel.php", { method: "POST" }).catch(() => {});
    document.getElementById("login-otp-panel").hidden = true;
    document.getElementById("back-to-login").hidden = true;
    document.getElementById("login-form").hidden = false;
    document.getElementById("login-otp-code").value = "";
    document.getElementById("email").focus();
}

async function restoreLoginOtp() {
    try {
        const res = await fetch(API + "login_otp_status.php", { cache: "no-store" });
        const data = await res.json();
        if (data.success && data.pending) {
            document.getElementById("login-form").hidden = true;
            document.getElementById("login-otp-panel").hidden = false;
            document.getElementById("back-to-login").hidden = false;
            showLoginOtpMessage(data);
        }
    } catch (err) {
        console.warn("Could not restore pending login verification.", err);
    }
}

/* Display an E.164 Philippine number in local format */
function formatPhPhone(e164) {
    if (!e164) return "";
    return e164.startsWith("+63") ? "0" + e164.slice(3) : e164;
}

/* Load plans */
async function loadPlans(containerId, isPublic = false) {
    const box = document.getElementById(containerId);
    if (!box) return;

    try {
        const res = await fetch(API + "get_plans.php");
        const plans = await res.json();

        if (!plans.length) {
            box.innerHTML = `<p style="color:var(--text-muted);text-align:center;grid-column:1/-1;">No plans available at this time.</p>`;
            return;
        }

        const planBadges = ['Popular', 'Fiber Fast', 'Best Value', 'Unlimited', 'Standard'];
        const planImages = [
            'https://images.unsplash.com/photo-1544717305-2782549b5136?w=600&q=80',
            'https://images.unsplash.com/photo-1518770660439-4636190af475?w=600&q=80',
            'https://images.unsplash.com/photo-1451187580459-43490279c0fa?w=600&q=80',
            'https://images.unsplash.com/photo-1550751827-4bd374c3f58b?w=600&q=80'
        ];

        box.innerHTML = plans.map((p, idx) => {
            const badge = planBadges[idx % planBadges.length];
            // Admin-uploaded picture wins; otherwise fall back to a stock image.
            const img = p.image ? esc(p.image) : planImages[idx % planImages.length];
            return `
            <div class="plan-card" style="display:flex;flex-direction:column;justify-content:space-between;padding:0;overflow:hidden">
                <div style="height:140px;background:linear-gradient(180deg,rgba(10,22,40,0.15) 0%,rgba(10,22,40,0.7) 100%),url('${img}') center/cover no-repeat;padding:16px;display:flex;flex-direction:column;justify-content:space-between;align-items:flex-start">
                    <span class="badge" style="background:#ffffff;color:#0a1628;font-size:0.75rem;font-weight:600;letter-spacing:0.3px">${badge}</span>
                    <span style="color:#ffffff;font-size:0.8rem;font-weight:500;background:rgba(10,22,40,0.65);padding:4px 10px;border-radius:var(--radius-pill)">${esc(p.duration_hours)} Hours</span>
                </div>
                <div style="padding:24px 22px 22px;display:flex;flex-direction:column;flex:1;justify-content:space-between">
                    <div>
                        <h4 style="margin-bottom:8px">${esc(p.name)}</h4>
                        <p style="margin-bottom:14px">${esc(p.description || 'Reliable fiber internet access powered by Converge ICT.')}</p>
                    </div>
                    <div>
                        <div class="price" style="margin:10px 0 16px">₱${parseFloat(p.price).toFixed(2)}</div>
                        ${isPublic
                            ? `<button class="btn-primary" style="width:100%" onclick="handlePublicBuy()">Select Plan</button>`
                            : `<button class="btn-primary" style="width:100%" onclick="buyPlan(${p.id})">Get Plan</button>`
                        }
                    </div>
                </div>
            </div>`;
        }).join("");
    } catch (err) {
        box.innerHTML = `<p style="color:#ff7a6b;text-align:center;grid-column:1/-1;">Unable to load plans. Please verify database connection.</p>`;
    }
}

/* Handling public Buy button on landing page */
function handlePublicBuy() {
    const user = JSON.parse(localStorage.getItem("user") || "null");
    if (user && user.id) {
        window.location.href = "dashboard.html";
    } else {
        window.location.href = "login.html";
    }
}

/* Buy a plan */
async function buyPlan(planId) {
    try {
        const res = await fetch(API + "buy_plan.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ plan_id: planId })
        });
        const data = await res.json();
        alert(data.message);
        if (data.success) {
            const balEl = document.getElementById("balance");
            if (balEl) balEl.textContent = parseFloat(data.new_balance).toFixed(2);

            // Update localStorage snapshot
            const user = JSON.parse(localStorage.getItem("user") || "{}");
            user.balance = data.new_balance;
            localStorage.setItem("user", JSON.stringify(user));

            // Refresh full session info
            checkSession();
        }
    } catch (err) {
        alert("Failed to process purchase. Please try again.");
    }
}

/* Real session check communicating with PHP server */
async function checkSession() {
    try {
        const res = await fetch(API + "get_session.php");
        const data = await res.json();

        if (!data.success || !data.user) {
            localStorage.removeItem("user");
            window.location.href = "login.html";
            return;
        }

        const user = data.user;
        localStorage.setItem("user", JSON.stringify(user));

        // Update greeting & balance
        const welcomeEl     = document.getElementById("welcome");
        const usernameEl    = document.getElementById("username");
        const balanceEl     = document.getElementById("balance");
        const adminLink     = document.getElementById("admin-link");
        const locationsLink = document.getElementById("locations-link");
        const topupLink     = document.getElementById("topup-link");

        if (welcomeEl)  welcomeEl.textContent = "Hi, " + user.fullname;
        if (usernameEl) usernameEl.textContent = user.fullname;
        if (balanceEl)  balanceEl.textContent  = parseFloat(user.balance || 0).toFixed(2);

        // Current mobile number (phone change card)
        const phoneEl = document.getElementById("current-phone");
        if (phoneEl) phoneEl.textContent = user.phone ? formatPhPhone(user.phone) : "Not set";
        const totpEl = document.getElementById("totp-status");
        if (totpEl) {
            totpEl.textContent = user.totp_enabled ? "Enabled" : "Not set up";
            totpEl.style.color = user.totp_enabled ? "var(--load-normal)" : "var(--text-muted)";
        }

        // Security: Show Admin & Locations links ONLY if admin
        const isAdmin = (user.role === 'admin');
        if (adminLink)     adminLink.style.display     = isAdmin ? "inline-block" : "none";
        if (locationsLink) locationsLink.style.display = isAdmin ? "inline-block" : "none";
        if (topupLink)     topupLink.style.display     = isAdmin ? "none" : "inline-block";

        // Location & Coverage Card
        const locCard = document.getElementById("location-card");
        if (locCard) {
            if (user.location) {
                const loc = user.location;
                locCard.style.display = "block";
                locCard.innerHTML = `
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
                        <div>
                            <span class="badge active" style="margin-bottom:6px">Active Coverage</span>
                            <h3 style="margin:4px 0 2px;color:var(--text);font-size:1.05rem">${esc(loc.address)}, ${esc(loc.city)}${loc.province ? ', ' + esc(loc.province) : ''}</h3>
                            <small style="color:var(--text-muted)">Region: <strong>${esc(loc.region)}</strong> &middot; Account Reference: <strong>${esc(loc.code)}</strong> &middot; Status: <strong style="color:var(--load-normal)">${esc(loc.signal)}</strong></small>
                        </div>
                        <div>
                            <span style="font-size:0.85rem;color:var(--load-normal);font-weight:600;border:1px solid rgba(34,197,94,0.3);padding:4px 12px;border-radius:var(--radius-pill);background:rgba(34,197,94,0.08)">Fiber Ready</span>
                        </div>
                    </div>
                `;
            } else {
                locCard.style.display = "none";
                locCard.innerHTML = "";
            }
        }

        // Active Subscription card
        const subCard = document.getElementById("active-sub-card");
        if (subCard) {
            if (user.active_subscription) {
                const sub = user.active_subscription;
                subCard.style.display = "block";
                subCard.innerHTML = `
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
                        <div>
                            <span class="badge active" style="margin-bottom:6px">Active Subscription</span>
                            <h3 style="margin:4px 0 2px;color:var(--accent-2)">${esc(sub.plan_name)}</h3>
                            <small style="color:var(--text-muted)">Valid until: <strong>${esc(sub.expires_at)}</strong></small>
                        </div>
                        <div>
                            <span style="font-size:0.85rem;color:var(--load-normal);font-weight:600;border:1px solid rgba(34,197,94,0.3);padding:4px 12px;border-radius:var(--radius-pill);background:rgba(34,197,94,0.08)">Online</span>
                        </div>
                    </div>
                `;
            } else {
                subCard.style.display = "none";
                subCard.innerHTML = "";
            }
        }
    } catch (err) {
        console.error("Session verification failed:", err);
    }
}
