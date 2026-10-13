document.addEventListener("DOMContentLoaded", () => {
    const dialog = document.getElementById("settings-dialog");
    const settingsPage = document.querySelector(".settings-page");
    if (!(dialog instanceof HTMLDialogElement) || !settingsPage) {
        return;
    }

    const cards = [...dialog.querySelectorAll(".settings-card")];
    const title = dialog.querySelector("#settings-dialog-title");
    const closeButton = dialog.querySelector("[data-settings-close]");
    let lastTrigger = null;

    const openPanel = (card, trigger = null) => {
        if (!cards.includes(card)) {
            return;
        }

        cards.forEach((panel) => {
            panel.hidden = panel !== card;
        });
        if (title) {
            title.textContent = card.querySelector("h2")?.textContent.trim() || "Settings";
        }
        lastTrigger = trigger;

        if (!dialog.open) {
            if (typeof dialog.showModal === "function") {
                dialog.showModal();
                dialog.classList.add("is-modal");
            } else {
                dialog.setAttribute("open", "");
            }
        }
    };

    const closePanel = () => {
        if (dialog.open) {
            dialog.close();
        }
        dialog.classList.remove("is-modal");
        if (lastTrigger instanceof HTMLElement) {
            lastTrigger.focus();
        }
        lastTrigger = null;
    };

    const initialPanelId = document.body.dataset.settingsPanel;
    const initialPanel = initialPanelId
        ? document.getElementById(initialPanelId)
        : null;

    if (dialog.open) {
        dialog.close();
    }
    cards.forEach((panel) => {
        panel.hidden = true;
    });

    settingsPage.addEventListener("click", (event) => {
        const link = event.target.closest('a[href^="#"]');
        if (!link) {
            return;
        }

        const targetId = decodeURIComponent(link.hash.slice(1));
        const target = document.getElementById(targetId);
        const card = target?.closest(".settings-card");
        if (card && dialog.contains(card)) {
            event.preventDefault();
            openPanel(card, link);
        }
    });

    closeButton?.addEventListener("click", closePanel);
    dialog.addEventListener("cancel", (event) => {
        event.preventDefault();
        closePanel();
    });
    dialog.addEventListener("click", (event) => {
        if (event.target === dialog) {
            closePanel();
        }
    });
    dialog.addEventListener("close", () => {
        dialog.classList.remove("is-modal");
        cards.forEach((panel) => {
            panel.hidden = true;
        });
    });
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && dialog.open) {
            event.preventDefault();
            closePanel();
        }
    });

    if (initialPanel instanceof HTMLElement) {
        openPanel(initialPanel);
    }
});
