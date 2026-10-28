let homepageCars = [];

document.addEventListener("DOMContentLoaded", async () => {
  const user = getStoredUser();
  const userMenu = document.getElementById("nav-user-menu");
  const authLinks = [
    document.getElementById("nav-auth"),
    document.getElementById("nav-register"),
  ];
  if (user) {
    userMenu.style.display = "block";
    document.getElementById("user-name").textContent = user.name;
    authLinks.forEach((link) => {
      if (link) link.style.display = "none";
    });
  }

  const carsGrid = document.getElementById("featured-cars-grid");
  const stats = document.getElementById("hero-stats");
  const reviewsGrid = document.getElementById("reviews-grid");
  document
    .getElementById("home-search-form")
    ?.addEventListener("submit", (event) => {
      event.preventDefault();
      const search = document.getElementById("home-search").value.trim();
      const category = document.getElementById("home-category").value;
      const params = new URLSearchParams();
      if (search) params.set("search", search);
      if (category) params.set("category", category);
      window.location.href = `cars.html${params.toString() ? `?${params}` : ""}`;
    });
  try {
    const [{ cars }, { reviews }] = await Promise.all([
      apiRequest("cars_api.php"),
      apiRequest("reviews.php"),
    ]);
    homepageCars = cars;
    const categories = new Set(homepageCars.map((car) => car.category));
    const locations = new Set(homepageCars.map((car) => car.location));
    stats.innerHTML = `<div class="col-md-3 metric"><strong>${homepageCars.length}+</strong><span>Vehicles ready</span></div><div class="col-md-3 metric"><strong>${categories.size}</strong><span>Categories</span></div><div class="col-md-3 metric"><strong>${locations.size}</strong><span>Locations</span></div><div class="col-md-3 metric"><strong>24/7</strong><span>Browse online</span></div>`;
    renderFeaturedCars(carsGrid);
    window.setInterval(() => renderFeaturedCars(carsGrid), 10000);
    reviewsGrid.innerHTML = reviews.length
      ? reviews.slice(0, 3).map(reviewCard).join("")
      : '<div class="col-12"><p class="text-secondary">Be the first customer to share a review.</p></div>';
  } catch (error) {
    stats.innerHTML =
      '<span class="text-secondary">Connect WAMP to load fleet data.</span>';
    carsGrid.innerHTML = `<div class="col-12"><div class="alert alert-warning">${escapeHtml(error.message)}</div></div>`;
  }
});

function renderFeaturedCars(container) {
  const shuffledCars = [...homepageCars].sort(() => Math.random() - 0.5);
  container.classList.remove("fleet-refreshing");
  void container.offsetWidth;
  container.classList.add("fleet-refreshing");
  container.innerHTML = shuffledCars.slice(0, 6).map(carCard).join("");
}

function carCard(car) {
  const image =
    car.image_url ||
    "https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=900&q=80";
  return `<div class="col-md-4"><article class="card car-card h-100"><img src="${escapeHtml(image)}" class="card-img-top" alt="${escapeHtml(`${car.make} ${car.model}`)}" /><div class="card-body d-flex flex-column"><div class="d-flex justify-content-between gap-2 mb-2"><span class="badge bg-warning text-dark">${escapeHtml(car.category)}</span><span class="text-secondary small">${escapeHtml(car.location)}</span></div><h3 class="h5">${escapeHtml(car.make)} ${escapeHtml(car.model)}</h3><p class="text-secondary mb-3">${car.year} model · carefully maintained</p><div class="mt-auto d-flex justify-content-between align-items-center"><strong class="text-warning">${formatMoney(car.price_per_day)}<small class="text-secondary"> / day</small></strong><a href="cars.html" class="btn btn-outline-warning btn-sm">View details</a></div></div></article></div>`;
}

function reviewCard(review) {
  return `<div class="col-md-4"><article class="card h-100"><div class="card-body"><div class="text-warning mb-2">${"★".repeat(Number(review.rating))}${"☆".repeat(5 - Number(review.rating))}</div><p class="mb-3">“${escapeHtml(review.comment)}”</p><strong>${escapeHtml(review.name)}</strong><div class="small text-secondary">Verified customer</div></div></article></div>`;
}
