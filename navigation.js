document.addEventListener("click", function (event) {
    const button = event.target.closest("[data-back-button]");

    if (!button || !document.referrer) {
        return;
    }

    try {
        const previousUrl = new URL(document.referrer);
        if (previousUrl.origin === window.location.origin) {
            event.preventDefault();
            window.history.back();
        }
    } catch (error) {
        // Keep the link's local fallback destination.
    }
});
