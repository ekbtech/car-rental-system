(function () {
  const projectRoot = window.location.pathname.includes("/auth/")
    ? window.location.pathname.split("/auth/")[0]
    : window.location.pathname.slice(
        0,
        window.location.pathname.lastIndexOf("/"),
      );
  const apiBase = `${window.location.origin}${projectRoot}/backend`;

  window.API = apiBase;
  window.getApiBase = () => apiBase;

  window.apiRequest = async function apiRequest(path, options = {}) {
    const isFormData = options.body instanceof FormData;
    const response = await fetch(`${apiBase}/${path}`, {
      credentials: "same-origin",
      headers: isFormData
        ? options.headers || {}
        : { "Content-Type": "application/json", ...(options.headers || {}) },
      ...options,
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw new Error(data.error || `Request failed (${response.status})`);
    }
    return data;
  };

  window.escapeHtml = function escapeHtml(value) {
    return String(value ?? "").replace(
      /[&<>'"]/g,
      (character) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          "'": "&#039;",
          '"': "&quot;",
        })[character],
    );
  };

  window.formatMoney = (value) => `$${Number(value || 0).toFixed(2)}`;
  window.formatDate = (value) =>
    new Date(value).toLocaleDateString(undefined, {
      month: "short",
      day: "numeric",
      year: "numeric",
    });

  window.getStoredUser = function getStoredUser() {
    try {
      return JSON.parse(localStorage.getItem("user") || "null");
    } catch (error) {
      return null;
    }
  };

  window.setStoredUser = (user) => {
    if (user) localStorage.setItem("user", JSON.stringify(user));
    else localStorage.removeItem("user");
  };

  window.refreshPage = () => window.location.reload();

  window.logout = async function logout() {
    try {
      await apiRequest("auth.php?action=logout", {
        method: "POST",
        body: "{}",
      });
    } finally {
      setStoredUser(null);
      window.location.href = "auth/login.html";
    }
  };
})();
