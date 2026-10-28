window.buildBookingPayload = function buildBookingPayload(draft) {
  return {
    car_id: Number(draft.car_id || draft.carId),
    user_id: Number(draft.user_id || draft.userId),
    start_date: draft.start_date || draft.startDate,
    end_date: draft.end_date || draft.endDate,
    pickup_location: draft.pickup_location || draft.pickupLocation || "",
    need_driver: Boolean(draft.need_driver ?? draft.needDriver),
  };
};

window.buildBookingSummary = function buildBookingSummary(draft) {
  if (!draft) return '<p class="text-secondary">No booking details found.</p>';
  return `<dl class="row mb-0"><dt class="col-sm-4 text-secondary">Vehicle</dt><dd class="col-sm-8">${escapeHtml(draft.carName || `${draft.make || "Selected"} ${draft.model || "car"}`)}</dd><dt class="col-sm-4 text-secondary">Dates</dt><dd class="col-sm-8">${escapeHtml(draft.start_date || draft.startDate)} to ${escapeHtml(draft.end_date || draft.endDate)}</dd><dt class="col-sm-4 text-secondary">Pickup</dt><dd class="col-sm-8">${escapeHtml(draft.pickup_location || draft.pickupLocation || "To be confirmed")}</dd></dl>`;
};
