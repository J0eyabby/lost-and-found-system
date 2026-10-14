document.addEventListener("click", (event) => {
    const toggle = event.target.closest("[data-profile-menu-toggle]");
    const menus = document.querySelectorAll(".profile-menu");

    if (toggle) {
        const menu = document.getElementById(toggle.getAttribute("aria-controls"));
        const isOpening = toggle.getAttribute("aria-expanded") !== "true";
        menus.forEach((otherMenu) => {
            otherMenu.hidden = true;
            otherMenu.classList.remove("is-open");
            const otherToggle = document.querySelector(`[aria-controls="${otherMenu.id}"]`);
            otherToggle?.setAttribute("aria-expanded", "false");
        });

        if (menu && isOpening) {
            menu.hidden = false;
            menu.classList.add("is-open");
            toggle.setAttribute("aria-expanded", "true");
        }
        return;
    }

    if (!event.target.closest(".site-icon-control")) {
        menus.forEach((menu) => {
            menu.hidden = true;
            menu.classList.remove("is-open");
            document.querySelector(`[aria-controls="${menu.id}"]`)?.setAttribute("aria-expanded", "false");
        });
    }
});

document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") {
        return;
    }
    document.querySelectorAll(".profile-menu").forEach((menu) => {
        menu.hidden = true;
        menu.classList.remove("is-open");
        document.querySelector(`[aria-controls="${menu.id}"]`)?.setAttribute("aria-expanded", "false");
    });
});

document.addEventListener("click", (event) => {
    const backLink = event.target.closest("[data-history-back]");
    if (!backLink || !document.referrer) {
        return;
    }

    try {
        const previousUrl = new URL(document.referrer);
        if (previousUrl.origin === window.location.origin && previousUrl.href !== window.location.href) {
            event.preventDefault();
            window.history.back();
        }
    } catch {
        // The link's local fallback remains available.
    }
});

let savedTextSize = "normal";
try {
    savedTextSize = localStorage.getItem("lost-found-text-size") || "normal";
} catch {
    savedTextSize = "normal";
}
if (["normal", "large", "larger"].includes(savedTextSize)) {
    document.documentElement.dataset.textSize = savedTextSize;
    const textSizeSetting = document.querySelector("[data-text-size-setting]");
    if (textSizeSetting) {
        textSizeSetting.value = savedTextSize;
    }
}

document.addEventListener("change", (event) => {
    if (event.target.matches("[data-text-size-setting]")) {
        const textSize = event.target.value;
        if (["normal", "large", "larger"].includes(textSize)) {
            document.documentElement.dataset.textSize = textSize;
            try {
                localStorage.setItem("lost-found-text-size", textSize);
            } catch {
                event.target.setCustomValidity("Your browser blocked saving this accessibility preference.");
                event.target.reportValidity();
                return;
            }
            event.target.setCustomValidity("");
        }
    }
});
