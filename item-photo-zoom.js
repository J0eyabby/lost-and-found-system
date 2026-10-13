document.addEventListener("DOMContentLoaded", () => {
    const dialog = document.querySelector("[data-photo-zoom-dialog]");
    const zoomImage = dialog?.querySelector("[data-zoom-image]");
    const zoomLevel = dialog?.querySelector("[data-zoom-level]");
    if (!dialog || !zoomImage || !zoomLevel) {
        return;
    }

    let scale = 1;
    const setScale = (nextScale) => {
        scale = Math.min(4, Math.max(1, nextScale));
        zoomImage.style.width = `${scale * 100}%`;
        zoomLevel.textContent = `${Math.round(scale * 100)}%`;
    };

    document.addEventListener("click", (event) => {
        const trigger = event.target.closest("[data-zoom-photo]");
        if (!trigger) {
            return;
        }
        zoomImage.src = trigger.dataset.zoomPhoto;
        zoomImage.alt = trigger.dataset.zoomAlt || "Item photo";
        setScale(1);
        dialog.showModal();
    });

    dialog.addEventListener("click", (event) => {
        if (event.target.closest("[data-zoom-in]")) {
            setScale(scale + 0.25);
        } else if (event.target.closest("[data-zoom-out]")) {
            setScale(scale - 0.25);
        } else if (event.target.closest("[data-zoom-reset]")) {
            setScale(1);
        } else if (event.target.closest("[data-zoom-close]") || event.target === dialog) {
            dialog.close();
        }
    });
});
