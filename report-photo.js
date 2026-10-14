document.addEventListener("DOMContentLoaded", () => {
    const cameraInput = document.querySelector("#image_camera");
    const browseInput = document.querySelector("#image");
    const preview = document.querySelector("[data-photo-preview]");
    const nameLabel = document.querySelector("[data-photo-name]");
    if ((!cameraInput && !browseInput) || !preview || !nameLabel) {
        return;
    }

    let previewUrl = "";
    const updateSelection = (selectedInput, otherInput) => {
        if (otherInput) {
            otherInput.value = "";
        }
        const file = selectedInput.files?.[0];
        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
            previewUrl = "";
        }
        if (!file) {
            preview.hidden = true;
            preview.removeAttribute("src");
            nameLabel.textContent = "Choose a photo from your device or take one with its camera.";
            return;
        }
        if (!file.type.startsWith("image/")) {
            selectedInput.value = "";
            preview.hidden = true;
            nameLabel.textContent = "Choose a valid image file.";
            return;
        }
        previewUrl = URL.createObjectURL(file);
        preview.src = previewUrl;
        preview.hidden = false;
        nameLabel.textContent = `${file.name} selected.`;
    };

    if (cameraInput) {
        cameraInput.addEventListener("change", () => updateSelection(cameraInput, browseInput));
    }
    if (browseInput) {
        browseInput.addEventListener("change", () => updateSelection(browseInput, cameraInput));
    }
});
