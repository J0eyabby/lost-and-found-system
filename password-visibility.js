document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll('input[type="password"]').forEach((input) => {
        if (input.dataset.visibilityControl === "added") {
            return;
        }

        input.dataset.visibilityControl = "added";
        const wrapper = document.createElement("span");
        wrapper.className = "password-field";
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);

        const toggle = document.createElement("button");
        toggle.type = "button";
        toggle.className = "password-visibility-toggle";
        const eyeIcon = `
            <svg class="password-visibility-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M2.2 12s3.5-6 9.8-6 9.8 6 9.8 6-3.5 6-9.8 6-9.8-6-9.8-6Z"></path>
                <circle cx="12" cy="12" r="2.6"></circle>
            </svg>`;
        const hiddenIcon = `
            <svg class="password-visibility-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M2.2 12s3.5-6 9.8-6 9.8 6 9.8 6-3.5 6-9.8 6-9.8-6-9.8-6Z"></path>
                <circle cx="12" cy="12" r="2.6"></circle>
                <path d="M4 4l16 16"></path>
            </svg>`;
        toggle.innerHTML = hiddenIcon;
        toggle.setAttribute("aria-label", "Show password");
        toggle.title = "Show password";
        toggle.setAttribute("aria-pressed", "false");
        toggle.addEventListener("click", () => {
            const isVisible = input.type === "text";
            input.type = isVisible ? "password" : "text";
            const label = `${isVisible ? "Show" : "Hide"} password`;
            toggle.innerHTML = isVisible ? hiddenIcon : eyeIcon;
            toggle.setAttribute("aria-label", label);
            toggle.title = label;
            toggle.setAttribute("aria-pressed", String(!isVisible));
            input.focus();
        });
        wrapper.appendChild(toggle);
    });
});
