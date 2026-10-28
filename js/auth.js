document.addEventListener("DOMContentLoaded", () => {
  const form = document.querySelector("#login-form, #register-form");
  if (!form) return;
  const loginMessage = document.getElementById("auth-message");
  if (
    form.id === "login-form" &&
    new URLSearchParams(window.location.search).get("registered") === "1"
  ) {
    loginMessage.innerHTML =
      '<div class="alert alert-success">Account created successfully. Please sign in to continue.</div>';
  }
  const roleSelect = document.getElementById("role");
  const cvField = document.getElementById("cv-field");
  const roleHelp = document.getElementById("role-help");
  const updateRoleFields = () => {
    if (!roleSelect) return;
    const isDriver = roleSelect.value === "driver";
    cvField?.classList.toggle("d-none", !isDriver);
    document.getElementById("cv")?.toggleAttribute("required", isDriver);
    if (roleHelp) {
      roleHelp.textContent = isDriver
        ? "Drivers must submit a CV and wait for admin approval before signing in."
        : roleSelect.value === "company"
          ? "Companies require admin approval before they can submit car listings."
          : "Customers can browse and book available cars.";
    }
  };
  roleSelect?.addEventListener("change", updateRoleFields);
  updateRoleFields();
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const message = document.getElementById("auth-message");
    const isRegister = form.id === "register-form";
    const payload = isRegister
      ? new FormData()
      : {
          email: document.getElementById("email").value,
          password: document.getElementById("password").value,
        };
    if (isRegister) {
      payload.append("email", document.getElementById("email").value);
      payload.append("password", document.getElementById("password").value);
      payload.append("name", document.getElementById("name").value);
      payload.append("phone", document.getElementById("phone").value);
      payload.append("role", document.getElementById("role").value);
      const cv = document.getElementById("cv")?.files[0];
      if (cv) payload.append("cv", cv);
    }
    const button = form.querySelector("button");
    button.disabled = true;
    try {
      const data = await apiRequest(
        `auth.php?action=${isRegister ? "register" : "login"}`,
        {
          method: "POST",
          body: isRegister ? payload : JSON.stringify(payload),
          ...(isRegister ? { headers: {} } : {}),
        },
      );
      if (data.requires_2fa) {
        showTwoFactorFlow(data);
        button.disabled = false;
        return;
      }
      if (data.pending) {
        const modal = document.getElementById("approval-modal");
        if (modal && window.bootstrap) {
          const approvalMessage = document.getElementById("approval-message");
          if (approvalMessage) approvalMessage.textContent = data.message;
          bootstrap.Modal.getOrCreateInstance(modal).show();
          document.getElementById("approval-ok")?.addEventListener(
            "click",
            () => {
              window.location.href = "login.html";
            },
            { once: true },
          );
        } else {
          message.innerHTML = `<div class="alert alert-info">${escapeHtml(data.message)} <a class="alert-link" href="login.html">OK, go to sign in</a></div>`;
        }
        button.disabled = false;
        return;
      }
      if (isRegister) {
        setStoredUser(null);
        const projectRoot = window.location.pathname.split("/auth/")[0];
        window.location.href = `${window.location.origin}${projectRoot}/auth/login.html?registered=1`;
        return;
      }
      const { user } = data;
      setStoredUser(user);
      const requestedPage = new URLSearchParams(window.location.search).get(
        "next",
      );
      const projectRoot = window.location.pathname.split("/auth/")[0];
      const safePage =
        requestedPage && !requestedPage.includes("://")
          ? requestedPage.replace(/^\/+/, "")
          : "dashboard.html";
      window.location.href = `${window.location.origin}${projectRoot}/${safePage}`;
    } catch (error) {
      message.innerHTML = `<div class="alert alert-danger">${escapeHtml(error.message)}</div>`;
      button.disabled = false;
    }
  });
});

let activeTwoFactorChallenge = null;

function showTwoFactorFlow(data) {
  activeTwoFactorChallenge = data.challenge;
  document.getElementById("login-form")?.classList.add("d-none");
  document.getElementById("2fa-panel")?.classList.remove("d-none");
  if (data.requires_enrollment) {
    document.getElementById("enrollment-panel")?.classList.remove("d-none");
    document.getElementById("challenge-panel")?.classList.add("d-none");
    startEnrollment();
  } else {
    document.getElementById("challenge-panel")?.classList.remove("d-none");
    document.getElementById("enrollment-panel")?.classList.add("d-none");
  }
}

async function startEnrollment() {
  try {
    const data = await apiRequest("two_factor.php?action=enrollment_start", {
      method: "POST",
      body: JSON.stringify({ challenge: activeTwoFactorChallenge }),
    });
    document.getElementById("manual-secret").textContent = data.secret;
    const qrContainer = document.getElementById("qr-code");
    qrContainer.innerHTML = "";
    if (window.QRCode) new QRCode(qrContainer, { text: data.otpauth_uri, width: 180, height: 180 });
  } catch (error) {
    document.getElementById("2fa-message").innerHTML = `<div class="alert alert-danger">${escapeHtml(error.message)}</div>`;
  }
}

async function completeEnrollment() {
  const code = document.getElementById("enrollment-code").value;
  try {
    const data = await apiRequest("two_factor.php?action=enrollment_confirm", {
      method: "POST",
      body: JSON.stringify({ challenge: activeTwoFactorChallenge, code }),
    });
    document.getElementById("enrollment-panel")?.classList.add("d-none");
    document.getElementById("challenge-panel")?.classList.add("d-none");
    document.getElementById("recovery-codes-panel")?.classList.remove("d-none");
    document.getElementById("recovery-codes").textContent = data.recovery_codes.join("\n");
  } catch (error) {
    document.getElementById("2fa-message").innerHTML = `<div class="alert alert-danger">${escapeHtml(error.message)}</div>`;
  }
}

async function verifyChallenge(kind) {
  const recovery = kind === "recovery";
  const code = document.getElementById(recovery ? "recovery-code" : "challenge-code").value;
  try {
    const data = await apiRequest(`two_factor.php?action=${recovery ? "recovery_verify" : "login_verify"}`, {
      method: "POST",
      body: JSON.stringify({ challenge: activeTwoFactorChallenge, code }),
    });
    setStoredUser(data.user);
    redirectAfterAuthentication();
  } catch (error) {
    document.getElementById("2fa-message").innerHTML = `<div class="alert alert-danger">${escapeHtml(error.message)}</div>`;
  }
}

function redirectAfterAuthentication() {
  const requestedPage = new URLSearchParams(window.location.search).get("next");
  const projectRoot = window.location.pathname.split("/auth/")[0];
  const safePage = requestedPage && !requestedPage.includes("://") ? requestedPage.replace(/^\/+/ , "") : "dashboard.html";
  window.location.href = `${window.location.origin}${projectRoot}/${safePage}`;
}

document.addEventListener("DOMContentLoaded", () => {
  document.getElementById("confirm-enrollment")?.addEventListener("click", completeEnrollment);
  document.getElementById("verify-challenge")?.addEventListener("click", () => verifyChallenge("totp"));
  document.getElementById("verify-recovery")?.addEventListener("click", () => verifyChallenge("recovery"));
  document.getElementById("show-recovery")?.addEventListener("click", () => {
    document.getElementById("recovery-panel")?.classList.remove("d-none");
  });
  document.getElementById("2fa-signout")?.addEventListener("click", () => logout());
  document.getElementById("acknowledge-recovery")?.addEventListener("click", async () => {
    try {
      const data = await apiRequest("two_factor.php?action=recovery_acknowledgement", {
        method: "POST",
        body: JSON.stringify({ challenge: activeTwoFactorChallenge }),
      });
      setStoredUser(data.user);
      redirectAfterAuthentication();
    } catch (error) {
      document.getElementById("2fa-message").innerHTML = `<div class="alert alert-danger">${escapeHtml(error.message)}</div>`;
    }
  });
});
