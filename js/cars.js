let availableCars = [];

document.addEventListener("DOMContentLoaded", () => {
  loadCars();
  ["filter-category", "car-search", "sort-cars"].forEach((id) =>
    document.getElementById(id)?.addEventListener("input", renderCars),
  );
  document
    .getElementById("booking-form")
    ?.addEventListener("submit", submitBooking);
  ["start-date", "end-date", "need-driver"].forEach((id) =>
    document.getElementById(id)?.addEventListener("input", updateTotal),
  );
});

async function loadCars() {
  const grid = document.getElementById("cars-grid");
  try {
    const params = new URLSearchParams(window.location.search);
    const query = new URLSearchParams();
    if (params.get("search")) query.set("search", params.get("search"));
    if (params.get("category")) query.set("category", params.get("category"));
    if (params.get("category"))
      document.getElementById("filter-category").value = params.get("category");
    if (params.get("search"))
      document.getElementById("car-search").value = params.get("search");
    ({ cars: availableCars } = await apiRequest(
      `cars_api.php${query.toString() ? `?${query}` : ""}`,
    ));
    renderCars();
  } catch (error) {
    grid.innerHTML = `<div class="col-12"><div class="alert alert-warning">${escapeHtml(error.message)}</div></div>`;
  }
}

function renderCars() {
  const category = document.getElementById("filter-category").value;
  const search = document
    .getElementById("car-search")
    .value.toLowerCase()
    .trim();
  const sort = document.getElementById("sort-cars").value;
  const cars = availableCars.filter(
    (car) =>
      (!category || car.category === category) &&
      (!search ||
        `${car.make} ${car.model} ${car.category} ${car.location}`
          .toLowerCase()
          .includes(search)),
  );
  cars.sort((a, b) =>
    sort === "price-asc"
      ? a.price_per_day - b.price_per_day
      : sort === "price-desc"
        ? b.price_per_day - a.price_per_day
        : sort === "year-desc"
          ? b.year - a.year
          : `${a.make} ${a.model}`.localeCompare(`${b.make} ${b.model}`),
  );
  document.getElementById("cars-grid").innerHTML = cars.length
    ? cars.map((car) => carCard(car)).join("")
    : '<div class="col-12"><p class="text-secondary">No vehicles match those filters.</p></div>';
}

function carCard(car) {
  const image =
    car.image_url ||
    "https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=900&q=80";
  return `<div class="col-lg-4 col-md-6"><article class="card h-100"><img src="${escapeHtml(image)}" class="card-img-top" alt="${escapeHtml(`${car.make} ${car.model}`)}"><div class="card-body d-flex flex-column"><span class="badge bg-warning text-dark align-self-start mb-2">${escapeHtml(car.category)}</span><h3 class="h5">${escapeHtml(car.make)} ${escapeHtml(car.model)}</h3><p class="small text-secondary">${car.year} · ${escapeHtml(car.location)}</p><div class="mt-auto d-flex justify-content-between align-items-center gap-2"><strong class="text-warning">${formatMoney(car.price_per_day)}<small class="text-secondary"> / day</small></strong><button class="btn btn-warning text-dark btn-sm fw-semibold" onclick="openBooking(${Number(car.id)})">Book now</button></div></div></article></div>`;
}

window.openBooking = async function openBooking(id) {
  const user = getStoredUser();
  if (!user) {
    window.location.href = "auth/login.html?next=cars.html";
    return;
  }
  try {
    const session = await apiRequest("auth.php?action=me");
    if (!session.user) {
      setStoredUser(null);
      window.location.href = "auth/login.html?next=cars.html";
      return;
    }
  } catch (error) {
    alert("Your sign-in session has expired. Please sign in again.");
    setStoredUser(null);
    window.location.href = "auth/login.html?next=cars.html";
    return;
  }
  const car = availableCars.find((item) => Number(item.id) === Number(id));
  if (!car) {
    alert("This vehicle is no longer available. Please refresh the fleet.");
    return;
  }
  document.getElementById("booking-car-id").value = id;
  document.getElementById("booking-car-preview").innerHTML =
    bookingCarPreview(car);
  const modal = bootstrap.Modal.getOrCreateInstance(
    document.getElementById("bookingModal"),
  );
  modal.show();
  updateTotal();
};

function bookingCarPreview(car) {
  const image =
    car.image_url ||
    "https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=900&q=80";
  return `<div class="booking-preview-layout"><img src="${escapeHtml(image)}" alt="${escapeHtml(`${car.make} ${car.model}`)}" onerror="this.src='https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=900&q=80'" /><div><span class="badge bg-warning text-dark mb-2">${escapeHtml(car.category)}</span><h3 class="h4 mb-1">${escapeHtml(car.make)} ${escapeHtml(car.model)}</h3><p class="text-secondary small mb-2">${car.year} · ${escapeHtml(car.location)}</p><p class="small mb-2">${escapeHtml(car.description || "Well maintained and ready for your journey.")}</p><div class="booking-detail-list"><span>${escapeHtml(car.transmission || "Automatic")}</span><span>${escapeHtml(car.fuel_type || "Petrol")}</span><span>${car.seats || 5} seats</span></div><strong class="text-warning">${formatMoney(car.price_per_day)} <small class="text-secondary">per day</small></strong></div></div>`;
}

function updateTotal() {
  const car = availableCars.find(
    (item) =>
      Number(item.id) ===
      Number(document.getElementById("booking-car-id")?.value),
  );
  const start = new Date(document.getElementById("start-date")?.value);
  const end = new Date(document.getElementById("end-date")?.value);
  if (
    !car ||
    Number.isNaN(start.getTime()) ||
    Number.isNaN(end.getTime()) ||
    end <= start
  ) {
    document.getElementById("total-price").textContent = "$0.00";
    return;
  }
  const days = Math.max(1, Math.ceil((end - start) / 86400000));
  document.getElementById("total-price").textContent = formatMoney(
    days * Number(car.price_per_day) +
      (document.getElementById("need-driver").checked ? 25 : 0),
  );
}

async function submitBooking(event) {
  event.preventDefault();
  const form = event.currentTarget;
  try {
    await apiRequest("bookings.php", {
      method: "POST",
      body: JSON.stringify({
        car_id: Number(document.getElementById("booking-car-id").value),
        start_date: document.getElementById("start-date").value,
        end_date: document.getElementById("end-date").value,
        pickup_location: document.getElementById("pickup-location").value,
        need_driver: document.getElementById("need-driver").checked,
      }),
    });
    bootstrap.Modal.getInstance(document.getElementById("bookingModal")).hide();
    alert(
      "Booking request sent. You can follow its status from your dashboard.",
    );
    form.reset();
    document.getElementById("booking-car-preview").innerHTML = "";
  } catch (error) {
    alert(error.message);
  }
}
