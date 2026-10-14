document.addEventListener("DOMContentLoaded", () => {
    const from = document.querySelector("#date-from, #claim-date-from");
    const to = document.querySelector("#date-to, #claim-date-to");
    if (!from || !to) {
        return;
    }

    const syncRange = () => {
        to.min = from.value || "";
        if (from.value && to.value && to.value < from.value) {
            to.setCustomValidity("The end date must be on or after the start date.");
        } else {
            to.setCustomValidity("");
        }
    };

    from.addEventListener("input", syncRange);
    to.addEventListener("input", syncRange);
    syncRange();
});
