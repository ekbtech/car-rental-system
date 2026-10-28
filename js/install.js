(function () {
  window.addEventListener("beforeinstallprompt", (event) => {
    event.preventDefault();
    window.procarInstallPrompt = event;
  });

  if ("serviceWorker" in navigator && window.location.protocol !== "file:") {
    window.addEventListener("load", () => {
      navigator.serviceWorker.register("sw.js").catch(() => {});
    });
  }
})();
