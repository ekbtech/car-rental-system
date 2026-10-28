let dashboardCars = [];

document.addEventListener("DOMContentLoaded", async () => {
  const user = getStoredUser();
  if (!user) {
    window.location.href = "auth/login.html?next=dashboard.html";
    return;
  }
  try {
    const data = await apiRequest("auth.php?action=me");
    if (!data.user) throw new Error("Sign-in required");
  } catch (error) {
    setStoredUser(null);
    window.location.href = "auth/login.html?next=dashboard.html";
    return;
  }
  setText("user-email", user.email);
  setText("profile-name", user.name);
  setText("profile-email", user.email);
  if (localStorage.getItem("procar-theme") === "light") {
    document.body.classList.add("light-theme");
  }
  document.getElementById("logout-btn")?.addEventListener("click", logout);
  setupProfileMenu(user);
  setupSupportInbox();
  await configureRoleTabs(user);
  document
    .getElementById("customer-booking-form")
    ?.addEventListener("submit", prepareBookingReview);
  document
    .getElementById("booking-edit-btn")
    ?.addEventListener("click", () => showBookingStep("form"));
  document
    .getElementById("booking-confirm-btn")
    ?.addEventListener("click", confirmBooking);
  document
    .getElementById("payment-method-form")
    ?.addEventListener("submit", submitPayment);
  document
    .getElementById("admin-car-form")
    ?.addEventListener("submit", saveAdminCar);
  document
    .getElementById("customer-feedback-form")
    ?.addEventListener("submit", submitFeedback);
  document
    .getElementById("company-car-form")
    ?.addEventListener("submit", submitCompanyCar);
  if (user.role === "company") await loadCompanySubmissions();
  await Promise.all([
    loadBookings(user),
    loadDashboardCars(),
    user.role === "admin" ? loadAdminData() : Promise.resolve(),
  ]);
});

function setText(id, value) {
  const element = document.getElementById(id);
  if (element) element.textContent = value || "";
}

async function configureRoleTabs(user) {
  const roles = {
    driver: "driver-tab-li",
    company: "company-tab-li",
    admin: "admin-tab-li",
  };
  if (roles[user.role])
    document.getElementById(roles[user.role]).style.display = "block";
  if (user.role === "admin") {
    document.getElementById("admin-notifications-tab-li").style.display =
      "block";
    document.getElementById("admin-pending-bookings-tab-li").style.display =
      "block";
    document.getElementById("admin-support-tab-li").style.display = "block";
    await loadAdminApprovals();
    await loadSupportInbox("all");
  }
}

async function loadBookings(user) {
  try {
    const { bookings } = await apiRequest(
      `bookings.php${user.role === "admin" ? "?admin=1" : ""}`,
    );
    const target = document.getElementById(
      user.role === "admin" ? "admin-bookings-list" : "bookings-list",
    );
    const cards = bookings
      .map(
        (booking) =>
          `<div class="col-lg-6"><article class="card h-100"><div class="card-body"><div class="d-flex justify-content-between gap-2"><h3 class="h5">${escapeHtml(booking.car_name)}</h3><span class="badge ${booking.status === "approved" ? "bg-success" : "bg-warning text-dark"}">${escapeHtml(booking.status)}</span></div><p class="text-secondary mb-2">${formatDate(booking.start_date)} - ${formatDate(booking.end_date)}</p><p class="small mb-3">Pickup: ${escapeHtml(booking.pickup_location || "To be confirmed")}</p><strong class="text-warning">${formatMoney(booking.total_price)}</strong>${user.role === "admin" ? `<button class="btn btn-outline-light btn-sm float-end" onclick="updateBookingStatus(${booking.id}, 'approved')">Approve</button>` : ""}</div></article></div>`,
      )
      .join("");
    if (target)
      target.innerHTML =
        cards ||
        '<p class="text-secondary">No bookings yet. Browse the fleet to get started.</p>';
    const preview = document.getElementById("dashboard-bookings-preview");
    if (preview) preview.innerHTML = bookings.length ? bookings.slice(0, 2).map((booking) => `<div class="d-flex align-items-center justify-content-between border-top pt-2 mt-2"><div><strong>${escapeHtml(booking.car_name)}</strong><div class="small text-secondary">${formatDate(booking.start_date)} - ${formatDate(booking.end_date)}</div></div><span class="badge ${booking.status === "approved" ? "bg-success" : "bg-warning text-dark"}">${escapeHtml(booking.status)}</span></div>`).join("") : '<p class="text-secondary mb-0 mt-3">No bookings yet. Browse the fleet to get started.</p>';
  } catch (error) {
    setHtml(
      "bookings-list",
      `<div class="alert alert-warning">${escapeHtml(error.message)}</div>`,
    );
  }
}

async function loadDashboardCars() {
  try {
    ({ cars: dashboardCars } = await apiRequest("cars_api.php"));
    setHtml(
      "cars-list",
      dashboardCars
        .map(
          (car) =>
            `<div class="col-lg-4"><article class="card h-100"><img class="card-img-top" src="${escapeHtml(car.image_url || "https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=900&q=80")}" alt="${escapeHtml(`${car.make} ${car.model}`)}" onerror="this.src='https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=900&q=80'"><div class="card-body"><span class="badge bg-warning text-dark mb-2">${escapeHtml(car.category)}</span><h3 class="h5">${escapeHtml(car.make)} ${escapeHtml(car.model)}</h3><p class="text-secondary">${car.year} · ${escapeHtml(car.location)}</p><strong class="text-warning">${formatMoney(car.price_per_day)} / day</strong><button class="btn btn-warning text-dark btn-sm float-end" onclick="openDashboardBooking(${car.id})">Book</button></div></article></div>`,
        )
        .join(""),
    );
  } catch (error) {
    setHtml(
      "cars-list",
      `<div class="alert alert-warning">${escapeHtml(error.message)}</div>`,
    );
  }
}

window.openDashboardBooking = function openDashboardBooking(id) {
  document.getElementById("customer-booking-car-id").value = id;
  bootstrap.Modal.getOrCreateInstance(
    document.getElementById("customerBookingModal"),
  ).show();
};

function prepareBookingReview(event) {
  event.preventDefault();
  const car = dashboardCars.find(
    (item) =>
      Number(item.id) ===
      Number(document.getElementById("customer-booking-car-id").value),
  );
  const draft = {
    car_id: car?.id,
    carName: `${car?.make || "Selected"} ${car?.model || "car"}`,
    start_date: document.getElementById("customer-start-date").value,
    end_date: document.getElementById("customer-end-date").value,
    pickup_location: document.getElementById("customer-pickup-location").value,
    need_driver: document.getElementById("customer-need-driver").checked,
  };
  if (
    !car ||
    !draft.start_date ||
    !draft.end_date ||
    new Date(draft.end_date) <= new Date(draft.start_date)
  ) {
    alert("Choose a car and a valid date range.");
    return;
  }
  localStorage.setItem("bookingReviewDraft", JSON.stringify(draft));
  setHtml("booking-review-summary", buildBookingSummary(draft));
  showBookingStep("review");
}

function showBookingStep(step) {
  document
    .getElementById("booking-form-step")
    .classList.toggle("d-none", step !== "form");
  document
    .getElementById("booking-review-step")
    .classList.toggle("d-none", step !== "review");
}

async function confirmBooking() {
  const draft = JSON.parse(
    localStorage.getItem("bookingReviewDraft") || "null",
  );
  try {
    await apiRequest("bookings.php", {
      method: "POST",
      body: JSON.stringify(buildBookingPayload(draft)),
    });
    localStorage.removeItem("bookingReviewDraft");
    bootstrap.Modal.getInstance(
      document.getElementById("customerBookingModal"),
    ).hide();
    alert("Your booking request was sent to the admin.");
    await loadBookings(getStoredUser());
  } catch (error) {
    alert(error.message);
  }
}

async function loadAdminData() {
  try {
    const [{ cars }, { bookings }, { users }, { reviews }, { payments }] =
      await Promise.all([
        apiRequest("cars_api.php?admin=1"),
        apiRequest("bookings.php?admin=1"),
        apiRequest("users.php?all=1"),
        apiRequest("reviews.php"),
        apiRequest("payments.php"),
      ]);
    dashboardCars = cars;
    setHtml(
      "admin-cars-list",
      cars
        .map(
          (car) =>
            `<div class="col-12"><div class="card"><div class="card-body py-2 d-flex justify-content-between align-items-center"><span>${escapeHtml(car.make)} ${escapeHtml(car.model)} <small class="text-secondary">${car.year} · ${escapeHtml(car.location)}</small></span><span><strong class="text-warning me-3">${formatMoney(car.price_per_day)} / day</strong><button class="btn btn-outline-light btn-sm" onclick="editAdminCar(${car.id})">Edit</button></span></div></div></div>`,
        )
        .join(""),
    );
    renderAdminBookings(bookings);
    renderAdminUsers(users);
    renderAdminDriverCvs(users);
    renderAdminReviews(reviews);
    renderAdminPayments(payments);
    renderAdminReports(cars, bookings, users, reviews, payments);
  } catch (error) {
    ["admin-cars-list", "admin-bookings-list", "admin-users-list", "admin-driver-cvs-list", "admin-reviews-list", "admin-payments-list", "admin-reports-content"].forEach((id) => setHtml(id, `<div class="alert alert-warning">${escapeHtml(error.message)}</div>`));
  }
}

function renderAdminBookings(bookings) {
  setHtml("admin-bookings-list", bookings.length ? bookings.map((booking) => `<div class="col-md-6"><article class="card h-100"><div class="card-body"><div class="d-flex justify-content-between"><h5>${escapeHtml(booking.car_name)}</h5><span class="badge ${booking.status === "approved" ? "bg-success" : booking.status === "rejected" ? "bg-danger" : "bg-warning text-dark"}">${escapeHtml(booking.status)}</span></div><p class="small text-secondary">${escapeHtml(booking.start_date)} to ${escapeHtml(booking.end_date)} · ${escapeHtml(booking.pickup_location || "No pickup location")}</p><p class="mb-2">${formatMoney(booking.total_price)}</p>${booking.status === "pending" ? `<button class="btn btn-success btn-sm me-2" onclick="updateBookingStatus(${booking.id}, 'approved')">Approve</button><button class="btn btn-outline-danger btn-sm" onclick="updateBookingStatus(${booking.id}, 'rejected')">Reject</button>` : ""}</div></article></div>`).join("") : '<div class="col-12"><p class="text-secondary">No bookings yet.</p></div>');
}

function renderAdminUsers(users) {
  setHtml("admin-users-list", users.length ? users.map((user) => `<div class="col-md-6"><article class="card h-100"><div class="card-body"><h5>${escapeHtml(user.name)}</h5><p class="small text-secondary mb-1">${escapeHtml(user.email)} · ${escapeHtml(user.role)}</p><p class="mb-2">Status: <strong>${escapeHtml(user.approval_status)}</strong></p>${user.approval_status === "pending" ? `<button class="btn btn-success btn-sm me-2" onclick="reviewUser(${user.id}, 'approved')">Approve</button><button class="btn btn-outline-danger btn-sm" onclick="reviewUser(${user.id}, 'rejected')">Reject</button>` : ""}</div></article></div>`).join("") : '<div class="col-12"><p class="text-secondary">No driver or company applications.</p></div>');
}

function renderAdminDriverCvs(users) {
  const drivers = users.filter((user) => user.role === "driver");
  setHtml("admin-driver-cvs-list", drivers.length ? drivers.map((driver) => `<div class="col-md-6"><article class="card h-100"><div class="card-body"><h5>${escapeHtml(driver.name)}</h5><p class="small text-secondary">${escapeHtml(driver.email)} · ${escapeHtml(driver.approval_status)}</p>${driver.cv_path ? `<a class="btn btn-outline-light btn-sm" href="${escapeHtml(driver.cv_path)}" target="_blank" rel="noopener">View CV</a>` : '<span class="text-secondary small">No CV uploaded</span>'}</div></article></div>`).join("") : '<div class="col-12"><p class="text-secondary">No driver CVs uploaded.</p></div>');
}

function renderAdminReviews(reviews) {
  setHtml("admin-reviews-list", reviews.length ? reviews.map((review) => `<div class="col-md-6"><article class="card h-100"><div class="card-body"><h5>${escapeHtml(review.name)} · ${"★".repeat(Number(review.rating))}</h5><p>${escapeHtml(review.comment)}</p><small class="text-secondary">${escapeHtml(review.created_at)}</small></div></article></div>`).join("") : '<div class="col-12"><p class="text-secondary">No customer reviews yet.</p></div>');
}

function renderAdminPayments(payments) {
  setHtml("admin-payments-list", payments.length ? payments.map((payment) => `<div class="col-md-6"><article class="card h-100"><div class="card-body"><h5>${escapeHtml(payment.customer_name)} · ${formatMoney(payment.amount)}</h5><p class="small text-secondary">${escapeHtml(payment.car_name)} · ${escapeHtml(payment.method)} · ${escapeHtml(payment.status)}</p><p class="small mb-0">Reference: ${escapeHtml(payment.reference || "Not provided")}</p></div></article></div>`).join("") : '<div class="col-12"><p class="text-secondary">No payments recorded yet.</p></div>');
}

function renderAdminReports(cars, bookings, users, reviews, payments) {
  const revenue = payments.reduce((total, payment) => total + Number(payment.amount || 0), 0);
  const approvedCars = cars.filter((car) => Number(car.is_available) === 1).length;
  setHtml("admin-reports-content", `<div class="col-md-3"><div class="card p-3"><small class="text-secondary">Fleet</small><strong class="fs-4">${cars.length}</strong><span>${approvedCars} available</span></div></div><div class="col-md-3"><div class="card p-3"><small class="text-secondary">Bookings</small><strong class="fs-4">${bookings.length}</strong><span>${bookings.filter((booking) => booking.status === "pending").length} pending</span></div></div><div class="col-md-3"><div class="card p-3"><small class="text-secondary">Accounts</small><strong class="fs-4">${users.length}</strong><span>driver/company accounts</span></div></div><div class="col-md-3"><div class="card p-3"><small class="text-secondary">Revenue</small><strong class="fs-4">${formatMoney(revenue)}</strong><span>${reviews.length} reviews</span></div></div>`);
}

window.editAdminCar = function editAdminCar(id) {
  const car = dashboardCars.find((item) => Number(item.id) === Number(id));
  if (!car) return;
  document.getElementById("admin-car-id").value = car.id;
  document.getElementById("admin-make").value = car.make;
  document.getElementById("admin-model").value = car.model;
  document.getElementById("admin-year").value = car.year;
  document.getElementById("admin-category").value = car.category;
  document.getElementById("admin-price").value = car.price_per_day;
  document.getElementById("admin-location").value = car.location;
  document.getElementById("admin-available").checked = Number(car.is_available) === 1;
  document.getElementById("admin-car-form")?.scrollIntoView({ behavior: "smooth" });
};

async function loadCompanySubmissions() {
  try {
    const { cars } = await apiRequest("cars_api.php?mine=1");
    setHtml(
      "company-submissions",
      cars.length
        ? cars
            .map(
              (car) =>
                `<div class="col-md-6"><article class="card h-100"><div class="card-body"><div class="d-flex justify-content-between gap-2"><h6>${escapeHtml(car.make)} ${escapeHtml(car.model)}</h6><span class="badge ${car.approval_status === "approved" ? "bg-success" : car.approval_status === "rejected" ? "bg-danger" : "bg-warning text-dark"}">${escapeHtml(car.approval_status)}</span></div><p class="small text-secondary">${escapeHtml(car.location)} · ${formatMoney(car.price_per_day)} / day</p>${car.rejection_reason ? `<p class="small text-danger mb-0">${escapeHtml(car.rejection_reason)}</p>` : ""}</div></article></div>`,
            )
            .join("")
        : '<div class="col-12"><p class="text-secondary">Your submitted vehicles will appear here.</p></div>',
    );
  } catch (error) {
    setHtml(
      "company-submissions",
      `<div class="alert alert-warning">${escapeHtml(error.message)}</div>`,
    );
  }
}

async function submitCompanyCar(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const body = {
    make: document.getElementById("company-make").value,
    model: document.getElementById("company-model").value,
    year: document.getElementById("company-year").value,
    category: document.getElementById("company-category").value,
    seats: document.getElementById("company-seats").value,
    price: document.getElementById("company-price").value,
    location: document.getElementById("company-location").value,
    image_url: document.getElementById("company-image").value,
    transmission: document.getElementById("company-transmission").value,
    fuel_type: document.getElementById("company-fuel").value,
    description: document.getElementById("company-description").value,
  };
  try {
    await apiRequest("cars_api.php", {
      method: "POST",
      body: JSON.stringify(body),
    });
    form.reset();
    await loadCompanySubmissions();
    alert("Vehicle sent to the admin for review.");
  } catch (error) {
    alert(error.message);
  }
}

async function loadAdminApprovals() {
  try {
    const [{ users }, { cars }] = await Promise.all([
      apiRequest("users.php"),
      apiRequest("cars_api.php?pending=1"),
    ]);
    setHtml(
      "admin-users-list",
      users
        .map(
          (item) =>
            `<div class="col-md-6"><article class="card h-100"><div class="card-body"><div class="d-flex justify-content-between gap-2"><h6>${escapeHtml(item.name)}</h6><span class="badge bg-warning text-dark">${escapeHtml(item.role)}</span></div><p class="small text-secondary mb-2">${escapeHtml(item.email)}</p><p class="small">Status: ${escapeHtml(item.approval_status)}</p>${item.cv_path ? `<a class="btn btn-outline-light btn-sm me-2" href="${escapeHtml(item.cv_path)}" target="_blank" rel="noopener">View CV</a>` : ""}${item.approval_status === "pending" ? `<button class="btn btn-success btn-sm me-2" onclick="reviewUser(${item.id}, 'approved')">Approve</button><button class="btn btn-outline-danger btn-sm" onclick="reviewUser(${item.id}, 'rejected')">Reject</button>` : ""}</div></article></div>`,
        )
        .join("") ||
        '<div class="col-12"><p class="text-secondary">No pending account applications.</p></div>',
    );
    setHtml(
      "admin-pending-list",
      cars
        .map(
          (car) =>
            `<div class="col-md-6"><article class="card h-100"><div class="card-body"><div class="d-flex justify-content-between gap-2"><h6>${escapeHtml(car.make)} ${escapeHtml(car.model)}</h6><span class="badge bg-warning text-dark">Pending car</span></div><p class="small text-secondary">${car.year} · ${escapeHtml(car.category)} · ${escapeHtml(car.location)}</p><p class="small mb-2">${escapeHtml(car.description || "No description provided.")}</p><p class="text-warning fw-semibold">${formatMoney(car.price_per_day)} / day</p><button class="btn btn-success btn-sm me-2" onclick="reviewCar(${car.id}, 'approved')">Add to dashboard</button><button class="btn btn-outline-danger btn-sm" onclick="reviewCar(${car.id}, 'rejected')">Reject</button></div></article></div>`,
        )
        .join("") ||
        '<div class="col-12"><p class="text-secondary">No pending vehicle submissions.</p></div>',
    );
  } catch (error) {
    setHtml(
      "admin-users-list",
      `<div class="alert alert-warning">${escapeHtml(error.message)}</div>`,
    );
  }
}

window.reviewUser = async function reviewUser(id, status) {
  try {
    await apiRequest("users.php", {
      method: "PUT",
      body: JSON.stringify({ id, approval_status: status }),
    });
    await loadAdminApprovals();
    alert(status === "approved" ? "Account approved." : "Account rejected.");
  } catch (error) {
    alert(error.message);
  }
};

window.reviewCar = async function reviewCar(id, status) {
  try {
    await apiRequest(`cars_api.php?id=${id}`, {
      method: "PUT",
      body: JSON.stringify({ id, approval_status: status }),
    });
    await loadAdminApprovals();
    await loadAdminData();
    alert(
      status === "approved"
        ? "Vehicle added to the dashboard."
        : "Vehicle rejected.",
    );
  } catch (error) {
    alert(error.message);
  }
};

async function saveAdminCar(event) {
  event.preventDefault();
  const carId = document.getElementById("admin-car-id").value;
  const body = {
    make: document.getElementById("admin-make").value,
    model: document.getElementById("admin-model").value,
    year: document.getElementById("admin-year").value,
    category: document.getElementById("admin-category").value,
    price: document.getElementById("admin-price").value,
    location: document.getElementById("admin-location").value,
    is_available: document.getElementById("admin-available").checked,
  };
  try {
    await apiRequest(carId ? `cars_api.php?id=${carId}` : "cars_api.php", {
      method: carId ? "PUT" : "POST",
      body: JSON.stringify(body),
    });
    event.target.reset();
    document.getElementById("admin-car-id").value = "";
    await loadAdminData();
    alert(carId ? "Car updated." : "Car saved.");
  } catch (error) {
    alert(error.message);
  }
}

window.updateBookingStatus = async function updateBookingStatus(id, status) {
  try {
    await apiRequest(`bookings.php?id=${id}`, {
      method: "PUT",
      body: JSON.stringify({ id, status }),
    });
    await loadBookings(getStoredUser());
  } catch (error) {
    alert(error.message);
  }
};

async function submitPayment(event) {
  event.preventDefault();
  try {
    await apiRequest("payments.php", {
      method: "POST",
      body: JSON.stringify({
        booking_id: document.getElementById("payment-booking-id").value,
        method: document.getElementById("payment-method-select").value,
        reference: document.getElementById("payment-reference-input").value,
      }),
    });
    alert("Payment recorded.");
  } catch (error) {
    alert(error.message);
  }
}
async function submitFeedback(event) {
  event.preventDefault();
  try {
    await apiRequest("reviews.php", {
      method: "POST",
      body: JSON.stringify({
        rating: document.getElementById("customer-feedback-rating").value,
        comment: document.getElementById("customer-feedback-comment").value,
      }),
    });
    event.target.reset();
    alert("Thanks for sharing your feedback.");
  } catch (error) {
    alert(error.message);
  }
}

function setupProfileMenu(user) {
  const sidebar = document.getElementById("dashboard-profile-sidebar");
  const backdrop = document.getElementById("sidebar-backdrop");
  const triggers = document.querySelectorAll("#profile-menu-trigger, #mobile-sidebar-trigger");
  const closeButton = document.getElementById("profile-menu-close");
  const initials = (user.name || user.email || "User").split(/\s+/).map((part) => part[0]).join("").slice(0, 2).toUpperCase() || "U";
  const avatar = document.querySelector(".sidebar-avatar");
  const topAvatar = document.getElementById("topbar-avatar");
  if (avatar) avatar.textContent = initials;
  if (topAvatar) topAvatar.textContent = initials;

  const setMenuState = (isOpen) => {
    sidebar?.classList.toggle("is-open", isOpen);
    backdrop?.classList.toggle("is-open", isOpen);
    document.body.classList.toggle("profile-sidebar-open", isOpen);
    sidebar?.setAttribute("aria-expanded", String(isOpen));
    sidebar?.setAttribute("aria-hidden", String(!isOpen));
    triggers.forEach((trigger) => trigger.setAttribute("aria-expanded", String(isOpen)));
  };
  triggers.forEach((trigger) => trigger.addEventListener("click", () => setMenuState(!sidebar?.classList.contains("is-open"))));
  closeButton?.addEventListener("click", () => setMenuState(false));
  backdrop?.addEventListener("click", () => setMenuState(false));

  document.querySelectorAll("[data-profile-action]").forEach((button) => {
    button.addEventListener("click", () => {
      const action = button.dataset.profileAction === "security" ? "authenticator" : button.dataset.profileAction;
      if (action === "help" && user.role === "admin") {
        document.body.classList.add("show-dashboard-detail");
        if (window.bootstrap) bootstrap.Tab.getOrCreateInstance(document.getElementById("admin-support-tab")).show();
        setMenuState(false);
        loadSupportInbox("all");
        return;
      }
      renderProfileAction(action, user);
      const modalElement = document.getElementById("profile-action-modal");
      if (modalElement && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalElement).show();
      document.querySelectorAll("[data-profile-action]").forEach((item) => item.classList.remove("active"));
      button.classList.add("active");
      setMenuState(false);
    });
  });

  const activateTab = (target) => {
    document.body.classList.add("show-dashboard-detail");
    const tab = document.querySelector(target);
    if (tab && window.bootstrap) bootstrap.Tab.getOrCreateInstance(tab).show();
  };
  document.querySelectorAll("[data-dashboard-nav]").forEach((button) => {
    button.addEventListener("click", () => {
      const destination = button.dataset.dashboardNav;
      document.querySelectorAll("[data-dashboard-nav]").forEach((item) => item.classList.remove("active"));
      document.querySelectorAll(`[data-dashboard-nav="${destination}"]`).forEach((item) => item.classList.add("active"));
      if (destination === "dashboard") document.body.classList.remove("show-dashboard-detail");
      if (destination === "cars") activateTab("#cars-tab");
      if (destination === "bookings") activateTab("#bookings-tab");
      if (destination === "favorites") {
        renderProfileAction("favorites", user);
        if (window.bootstrap) bootstrap.Modal.getOrCreateInstance(document.getElementById("profile-action-modal")).show();
      }
      setMenuState(false);
    });
  });
  document.getElementById("hero-browse-cars")?.addEventListener("click", () => activateTab("#cars-tab"));
  document.getElementById("view-all-bookings")?.addEventListener("click", () => activateTab("#bookings-tab"));
  document.getElementById("dashboard-notifications")?.addEventListener("click", () => { renderProfileAction("notifications", user); bootstrap.Modal.getOrCreateInstance(document.getElementById("profile-action-modal")).show(); });
  document.getElementById("travel-learn-more")?.addEventListener("click", () => { renderProfileAction("about", user); bootstrap.Modal.getOrCreateInstance(document.getElementById("profile-action-modal")).show(); });
  document.getElementById("logout-btn")?.addEventListener("click", logout);
  document.querySelectorAll("details.sidebar-section summary").forEach((summary) => summary.addEventListener("click", () => setTimeout(() => window.lucide?.createIcons(), 0)));
  document.getElementById("dashboard-search-input")?.addEventListener("input", (event) => {
    const term = event.target.value.trim().toLowerCase();
    if (!term) return;
    document.body.classList.add("show-dashboard-detail");
    activateTab("#cars-tab");
    document.querySelectorAll("#cars-list .card").forEach((card) => { card.closest(".col")?.classList.toggle("d-none", !card.textContent.toLowerCase().includes(term)); });
  });
  window.lucide?.createIcons();
}

function renderProfileAction(action, user) {
  const content = {
    "manage-profile": `<h6>Manage Profile</h6><form id="profile-form"><label class="form-label" for="profile-name-input">Name</label><input id="profile-name-input" class="form-control bg-dark text-light mb-2" value="${escapeHtml(user.name)}" required><label class="form-label" for="profile-phone-input">Phone</label><input id="profile-phone-input" class="form-control bg-dark text-light mb-3" value="${escapeHtml(user.phone || "")}"><button class="btn btn-warning btn-sm text-dark">Save profile</button></form>`,
    notifications:
      '<h6>Notifications</h6><p class="text-secondary mb-0">You will see account, booking, and approval updates here.</p>',
    "language-settings":
      '<h6>Language Settings</h6><label class="form-label" for="language-select">Language</label><select id="language-select" class="form-select bg-dark text-light"><option value="en">English</option><option value="es">Español</option><option value="fr">Français</option><option value="de">Deutsch</option><option value="pt">Português</option><option value="sw">Kiswahili</option><option value="ar">العربية</option><option value="zh">中文</option><option value="hi">हिन्दी</option><option value="ja">日本語</option><option value="ko">한국어</option><option value="it">Italiano</option><option value="nl">Nederlands</option><option value="tr">Türkçe</option><option value="ru">Русский</option></select>',
    theme:
      '<h6>Theme</h6><button id="theme-toggle" class="btn btn-outline-warning btn-sm">Toggle light theme</button>',
    password:
      '<h6>Change Password</h6><form id="password-form"><input id="current-password" type="password" class="form-control bg-dark text-light mb-2" placeholder="Current password" required><input id="new-password" type="password" class="form-control bg-dark text-light mb-3" placeholder="New password (8+ characters)" minlength="8" required><button class="btn btn-warning btn-sm text-dark">Update password</button></form>',
    authenticator:
      '<h6>Authenticator &amp; recovery</h6><div id="authenticator-status" class="text-secondary mb-3">Loading security status...</div><div id="authenticator-actions" class="d-grid gap-2"><button type="button" class="btn btn-outline-warning btn-sm" data-authenticator-action="replace">Replace authenticator</button><button type="button" class="btn btn-outline-warning btn-sm" data-authenticator-action="recover">Regenerate recovery codes</button></div><div id="authenticator-result" class="mt-3"></div>',
    favorites: '<h6>Favorites</h6><p class="text-secondary mb-0">Saved cars will appear here when you select the heart on a vehicle.</p>',
    help: `<h6>Help &amp; Support</h6><p class="text-secondary">Send our support team a message and we will get back to you.</p><form id="support-form" class="support-form" action="https://formspree.io/f/meaagydz" method="POST"><input type="hidden" name="_subject" value="New ProCar support request"><input type="hidden" name="account_name" value="${escapeHtml(user.name || "")}"><div><label for="support-name">Name</label><input id="support-name" name="name" class="form-control" value="${escapeHtml(user.name || "")}" required></div><div><label for="support-email">Email</label><input id="support-email" type="email" name="email" class="form-control" value="${escapeHtml(user.email || "")}" required></div><div><label for="support-subject">Subject</label><input id="support-subject" name="subject" class="form-control" maxlength="180" required></div><div><label for="support-category">Category</label><select id="support-category" name="topic" class="form-select"><option>Booking</option><option>Payment</option><option>Account</option><option>Authenticator</option><option>Technical issue</option><option>Other</option></select></div><div><label for="support-message">Message</label><textarea id="support-message" name="message" class="form-control" rows="5" minlength="10" placeholder="Describe how we can help..." required></textarea></div><button type="submit" class="btn">Send message</button><div id="support-status" class="support-status" role="status" aria-live="polite"></div></form>`,
    about:
      '<h6>About Pro Car</h6><p class="text-secondary mb-0">A direct, approval-based car rental marketplace for customers, drivers, companies, and administrators.</p>',
    install:
      '<h6>Install Pro Car</h6><p class="text-secondary">Install the app for quicker access from your device.</p><button id="install-app-button" class="btn btn-warning text-dark btn-sm">Install app</button><p id="install-help" class="small text-secondary mt-2 mb-0"></p>',
    logout:
      '<h6>Log Out</h6><button class="btn btn-warning btn-sm text-dark" onclick="logout()">Log out now</button>',
  };
  setHtml("profile-action-content", content[action] || content.about);
  document
    .getElementById("profile-form")
    ?.addEventListener("submit", updateProfile);
  document
    .getElementById("password-form")
    ?.addEventListener("submit", changePassword);
  document.getElementById("theme-toggle")?.addEventListener("click", () => {
    document.body.classList.toggle("light-theme");
    localStorage.setItem(
      "procar-theme",
      document.body.classList.contains("light-theme") ? "light" : "dark",
    );
  });
  document
    .getElementById("language-select")
    ?.addEventListener("change", (event) => {
      localStorage.setItem("procar-language", event.target.value);
      window.applyLanguage?.(event.target.value);
    });
  const languageSelect = document.getElementById("language-select");
  if (languageSelect) {
    languageSelect.value = localStorage.getItem("procar-language") || "en";
  }
  if (action === "authenticator") setupAuthenticatorActions(user);
  if (action === "help") setupSupportForm();
  document
    .getElementById("install-app-button")
    ?.addEventListener("click", installApp);
  window.applyLanguage?.(localStorage.getItem("procar-language") || "en");
}

function setupSupportForm() {
  const form = document.getElementById("support-form");
  if (!form) return;
  const status = document.getElementById("support-status");
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const button = form.querySelector("button[type='submit']");
    button.disabled = true;
    button.textContent = "Sending...";
    status.className = "support-status";
    status.textContent = "";
    try {
      const fields = Object.fromEntries(new FormData(form).entries());
      const response = await fetch("backend/support.php?action=submit", {
        method: "POST",
        body: JSON.stringify(fields),
        headers: { Accept: "application/json", "Content-Type": "application/json" },
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.error || "Your message could not be sent. Please try again.");
      form.reset();
      status.className = "support-status success";
      status.textContent = data.message || "Message sent successfully. Our support team will be in touch.";
    } catch (error) {
      status.className = "support-status error";
      status.textContent = error.message;
    } finally {
      button.disabled = false;
      button.textContent = "Send message";
    }
  });
}

async function loadSupportInbox(filter = "all") {
  const list = document.getElementById("support-inbox-list");
  if (!list) return;
  list.innerHTML = '<p class="text-secondary mb-0">Loading support requests...</p>';
  try {
    const response = await fetch(`backend/support.php?action=list&status=${encodeURIComponent(filter)}`, { headers: { Accept: "application/json" } });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || "Could not load support requests.");
    const counts = data.counts || {};
    const newCount = Number(counts.new_count || 0);
    setText("support-notification-count", String(newCount));
    setText("support-filter-all", String(newCount + Number(counts.in_progress_count || 0) + Number(counts.resolved_count || 0)));
    setText("support-filter-new", String(newCount));
    setText("support-filter-progress", String(counts.in_progress_count || 0));
    setText("support-filter-resolved", String(counts.resolved_count || 0));
    document.querySelectorAll("[data-support-filter]").forEach((button) => button.classList.toggle("active", button.dataset.supportFilter === filter));
    if (!data.tickets.length) {
      list.innerHTML = '<div class="support-empty-state"><strong>No support requests found</strong><p class="text-secondary mb-0">New submissions will appear here automatically.</p></div>';
      return;
    }
    list.innerHTML = data.tickets.map((ticket) => {
      const statusClass = ticket.status === "resolved" ? "resolved" : ticket.status === "in_progress" ? "progress" : "new";
      const statusLabel = ticket.status === "in_progress" ? "In Progress" : ticket.status[0].toUpperCase() + ticket.status.slice(1);
      return `<button type="button" class="support-ticket-row" data-support-ticket-id="${ticket.id}"><span class="support-ticket-sender"><strong>${escapeHtml(ticket.sender_name)}</strong><small>${escapeHtml(ticket.sender_email)}</small></span><span class="support-ticket-copy"><strong>${escapeHtml(ticket.subject)}</strong><small>${escapeHtml(ticket.message_preview)}</small></span><span class="support-ticket-meta"><span class="support-status-badge ${statusClass}">${statusLabel}</span><time>${formatDate(ticket.submitted_at)}</time>${ticket.unread === 1 ? '<span class="support-unread-dot" title="Unread"></span>' : ''}</span></button>`;
    }).join("");
    list.querySelectorAll("[data-support-ticket-id]").forEach((button) => button.addEventListener("click", () => openSupportTicket(Number(button.dataset.supportTicketId))));
  } catch (error) {
    list.innerHTML = `<div class="alert alert-danger mb-0">${escapeHtml(error.message)}</div>`;
  }
}

async function openSupportTicket(ticketId) {
  const detail = document.getElementById("support-ticket-detail");
  const list = document.getElementById("support-inbox-list");
  if (!detail || !list) return;
  detail.classList.remove("d-none");
  list.classList.add("support-inbox-list-hidden");
  detail.innerHTML = '<p class="text-secondary mb-0">Loading ticket...</p>';
  try {
    const response = await fetch(`backend/support.php?action=detail&id=${ticketId}`, { headers: { Accept: "application/json" } });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || "Could not load this ticket.");
    const ticket = data.ticket;
    const replies = (data.replies || []).map((reply) => `<div class="support-reply"><div><strong>${escapeHtml(reply.admin_name || "Admin")}</strong><small>${formatDate(reply.created_at)}</small></div><p>${escapeHtml(reply.message)}</p></div>`).join("");
    detail.innerHTML = `<div class="support-ticket-toolbar"><button id="support-ticket-back" type="button" class="support-back-button">← Back to inbox</button><select id="support-ticket-status" class="form-select"><option value="new" ${ticket.status === "new" ? "selected" : ""}>New</option><option value="in_progress" ${ticket.status === "in_progress" ? "selected" : ""}>In Progress</option><option value="resolved" ${ticket.status === "resolved" ? "selected" : ""}>Resolved</option></select></div><div class="support-ticket-message"><div class="support-ticket-sender-detail"><span class="support-sender-avatar">${escapeHtml((ticket.sender_name || "U")[0].toUpperCase())}</span><div><strong>${escapeHtml(ticket.sender_name)}</strong><a href="mailto:${escapeHtml(ticket.sender_email)}">${escapeHtml(ticket.sender_email)}</a></div></div><span class="support-submitted-date">${formatDate(ticket.submitted_at)}</span><h3>${escapeHtml(ticket.subject)}</h3><p>${escapeHtml(ticket.message).replaceAll("\n", "<br>")}</p></div><div class="support-replies">${replies}</div><form id="support-reply-form" class="support-reply-form"><label for="support-admin-reply">Admin reply</label><textarea id="support-admin-reply" name="reply" class="form-control" rows="4" minlength="3" placeholder="Write a reply to this customer..." required></textarea><button type="submit" class="btn">Send reply &amp; update status</button><div id="support-update-status" class="support-status" role="status"></div></form>`;
    document.getElementById("support-ticket-back")?.addEventListener("click", () => { detail.classList.add("d-none"); list.classList.remove("support-inbox-list-hidden"); detail.innerHTML = ""; loadSupportInbox(document.querySelector("[data-support-filter].active")?.dataset.supportFilter || "all"); });
    document.getElementById("support-reply-form")?.addEventListener("submit", async (event) => {
      event.preventDefault();
      const reply = document.getElementById("support-admin-reply").value;
      const status = document.getElementById("support-ticket-status").value;
      const statusElement = document.getElementById("support-update-status");
      try {
        const updateResponse = await fetch("backend/support.php?action=update", { method: "PUT", headers: { Accept: "application/json", "Content-Type": "application/json" }, body: JSON.stringify({ id: ticketId, status, reply }) });
        const updateData = await updateResponse.json();
        if (!updateResponse.ok) throw new Error(updateData.error || "Could not update ticket.");
        statusElement.className = "support-status success";
        statusElement.textContent = updateData.emailed ? "Reply saved and emailed successfully." : "Reply saved. Email delivery is not configured.";
        openSupportTicket(ticketId);
        loadSupportInbox(document.querySelector("[data-support-filter].active")?.dataset.supportFilter || "all");
      } catch (error) { statusElement.className = "support-status error"; statusElement.textContent = error.message; }
    });
    document.getElementById("support-ticket-status")?.addEventListener("change", async (event) => {
      const updateResponse = await fetch("backend/support.php?action=update", { method: "PUT", headers: { Accept: "application/json", "Content-Type": "application/json" }, body: JSON.stringify({ id: ticketId, status: event.target.value }) });
      const updateData = await updateResponse.json().catch(() => ({}));
      if (!updateResponse.ok) alert(updateData.error || "Could not update status.");
      else { loadSupportInbox(document.querySelector("[data-support-filter].active")?.dataset.supportFilter || "all"); }
    });
    loadSupportInbox(document.querySelector("[data-support-filter].active")?.dataset.supportFilter || "all");
  } catch (error) { detail.innerHTML = `<div class="alert alert-danger mb-0">${escapeHtml(error.message)}</div>`; }
}

function setupSupportInbox() {
  document.querySelectorAll("[data-support-filter]").forEach((button) => button.addEventListener("click", () => { document.getElementById("support-ticket-detail")?.classList.add("d-none"); document.getElementById("support-inbox-list")?.classList.remove("support-inbox-list-hidden"); loadSupportInbox(button.dataset.supportFilter); }));
  document.getElementById("support-inbox-refresh")?.addEventListener("click", () => loadSupportInbox(document.querySelector("[data-support-filter].active")?.dataset.supportFilter || "all"));
}

function setupAuthenticatorActions(user) {
  const status = document.getElementById("authenticator-status");
  const result = document.getElementById("authenticator-result");
  apiRequest("two_factor.php?action=status")
    .then((data) => {
      status.innerHTML = data.totp_enabled
        ? `<span class="text-success fw-semibold">Enabled</span><br><small>${data.recovery_codes_remaining} recovery codes remaining</small>`
        : '<span class="text-warning fw-semibold">Setup required</span>';
    })
    .catch((error) => { status.textContent = error.message; });

  document.querySelectorAll("[data-authenticator-action]").forEach((button) => {
    button.addEventListener("click", () => {
      const replacing = button.dataset.authenticatorAction === "replace";
      result.innerHTML = `<form id="authenticator-password-form"><label class="form-label" for="authenticator-password">Current password</label><input id="authenticator-password" type="password" class="form-control bg-dark text-light mb-3" autocomplete="current-password" required><button class="btn btn-warning text-dark btn-sm w-100">${replacing ? "Start authenticator replacement" : "Regenerate recovery codes"}</button></form>`;
      document.getElementById("authenticator-password-form")?.addEventListener("submit", async (event) => {
        event.preventDefault();
        const password = document.getElementById("authenticator-password").value;
        try {
          if (!replacing) {
            const data = await apiRequest("two_factor.php?action=regenerate_recovery_codes", { method: "POST", body: JSON.stringify({ password }) });
            result.innerHTML = `<h6>New recovery codes</h6><p class="small text-secondary">Save these now; they are shown once.</p><pre class="bg-black text-warning border border-secondary rounded p-2">${escapeHtml(data.recovery_codes.join("\n"))}</pre>`;
            return;
          }
          const started = await apiRequest("two_factor.php?action=replacement_start", { method: "POST", body: JSON.stringify({ password }) });
          const enrollment = await apiRequest("two_factor.php?action=enrollment_start", { method: "POST", body: JSON.stringify({ challenge: started.challenge }) });
          result.innerHTML = `<h6>Scan with Google Authenticator</h6><div id="replacement-qr" class="bg-white d-inline-block p-2 rounded mb-2"></div><details><summary>Manual setup key</summary><code class="text-warning text-break">${escapeHtml(enrollment.secret)}</code></details><label class="form-label mt-3" for="replacement-code">Six-digit code</label><input id="replacement-code" class="form-control bg-dark text-light mb-2" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" required><button id="replacement-confirm" type="button" class="btn btn-warning text-dark btn-sm w-100">Activate authenticator</button>`;
          if (window.QRCode) new QRCode(document.getElementById("replacement-qr"), { text: enrollment.otpauth_uri, width: 180, height: 180 });
          document.getElementById("replacement-confirm")?.addEventListener("click", async () => {
            try {
              const confirmed = await apiRequest("two_factor.php?action=enrollment_confirm", { method: "POST", body: JSON.stringify({ challenge: started.challenge, code: document.getElementById("replacement-code").value }) });
              result.innerHTML = `<h6>Authenticator active</h6><p class="text-secondary">Save your new recovery codes shown once below.</p><pre class="bg-black text-warning border border-secondary rounded p-2">${escapeHtml(confirmed.recovery_codes.join("\n"))}</pre><button type="button" class="btn btn-warning text-dark btn-sm" onclick="location.reload()">Finish</button>`;
              status.innerHTML = '<span class="text-success fw-semibold">Enabled</span><br><small>10 recovery codes available</small>';
            } catch (error) { result.insertAdjacentHTML("beforeend", `<div class="alert alert-danger mt-2">${escapeHtml(error.message)}</div>`); }
          });
        } catch (error) { result.insertAdjacentHTML("beforeend", `<div class="alert alert-danger mt-2">${escapeHtml(error.message)}</div>`); }
      });
    });
  });
}

async function installApp() {
  const help = document.getElementById("install-help");
  if (!window.procarInstallPrompt) {
    if (help) {
      help.textContent =
        "Use your browser menu and choose Install app or Add to home screen.";
    }
    return;
  }
  window.procarInstallPrompt.prompt();
  const choice = await window.procarInstallPrompt.userChoice;
  if (help) {
    help.textContent =
      choice.outcome === "accepted"
        ? "Pro Car was installed."
        : "Installation was cancelled.";
  }
  window.procarInstallPrompt = null;
}

async function updateProfile(event) {
  event.preventDefault();
  try {
    const { user } = await apiRequest("profile.php?action=update", {
      method: "PUT",
      body: JSON.stringify({
        name: document.getElementById("profile-name-input").value,
        phone: document.getElementById("profile-phone-input").value,
      }),
    });
    setStoredUser(user);
    setText("profile-name", user.name);
    setText("user-email", user.email);
    alert("Profile updated.");
  } catch (error) {
    alert(error.message);
  }
}

async function changePassword(event) {
  event.preventDefault();
  try {
    await apiRequest("profile.php?action=password", {
      method: "PUT",
      body: JSON.stringify({
        current_password: document.getElementById("current-password").value,
        new_password: document.getElementById("new-password").value,
      }),
    });
    event.target.reset();
    alert("Password updated.");
  } catch (error) {
    alert(error.message);
  }
}

function setHtml(id, html) {
  const element = document.getElementById(id);
  if (element) {
    element.innerHTML = html;
    window.applyLanguage?.(localStorage.getItem("procar-language") || "en");
  }
}
