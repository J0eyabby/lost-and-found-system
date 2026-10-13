document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll("[data-get-location]").forEach((button) => {
        button.addEventListener("click", () => {
            const status = button.closest(".handover-fields")?.querySelector("[data-location-status]");
            const latitude = document.getElementById("handover-latitude");
            const longitude = document.getElementById("handover-longitude");

            if (!navigator.geolocation) {
                status.textContent = "Location is not supported by this browser. Enter coordinates manually.";
                return;
            }

            status.textContent = "Requesting your location…";
            button.disabled = true;
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    latitude.value = position.coords.latitude.toFixed(7);
                    longitude.value = position.coords.longitude.toFixed(7);
                    status.textContent = "Location added. You can adjust the coordinates before saving.";
                    button.disabled = false;
                },
                () => {
                    status.textContent = "Could not get your location. Check browser permission or enter coordinates manually.";
                    button.disabled = false;
                },
                { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 }
            );
        });
    });
});
