document.addEventListener("click", async (event) => {
    const button = event.target.closest?.("[data-share-item]");
    if (!button) {
        return;
    }

    const feedback = button.closest(".item-detail-content")?.querySelector(".share-feedback");
    const url = new URL(button.dataset.shareUrl, window.location.href).href;
    try {
        if (navigator.share) {
            await navigator.share({ title: button.dataset.shareTitle, url });
            if (feedback) {
                showItemShareFeedback(feedback, "Item link shared.", url);
            }
            return;
        }
        await copyItemLink(url);
        if (feedback) {
            showItemShareFeedback(feedback, "Item link copied. Paste it into any app to share.", url);
        }
    } catch (error) {
        if (error.name === "AbortError") {
            return;
        }
        try {
            await copyItemLink(url);
            if (feedback) {
                showItemShareFeedback(feedback, "Sharing was unavailable, so the item link was copied instead.", url);
            }
        } catch (copyError) {
            if (feedback) {
                showItemShareFeedback(feedback, "Copy or open this item link:", url);
            }
        }
    }
});

function showItemShareFeedback(feedback, message, url) {
    const link = document.createElement("a");
    link.href = url;
    link.textContent = "Open item";
    link.className = "share-feedback-link";
    feedback.replaceChildren(document.createTextNode(`${message} `), link);
}

async function copyItemLink(url) {
    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(url);
        return;
    }
    const input = document.createElement("textarea");
    input.value = url;
    input.setAttribute("readonly", "");
    input.style.position = "fixed";
    input.style.opacity = "0";
    document.body.append(input);
    input.select();
    const copied = document.execCommand("copy");
    input.remove();
    if (!copied) {
        throw new Error("Clipboard access was unavailable.");
    }
}
