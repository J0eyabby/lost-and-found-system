document.addEventListener("DOMContentLoaded", () => {
    const language = document.documentElement.lang === "sw" ? "sw" : "en";
    const text = {
        en: {
            category: "Suggested category: ",
            noCategory: "No clear category suggestion. Please choose one manually.",
            generated: "A basic draft was added. Edit it to include only details you can verify.",
            moderation: "No common contact details or credential words detected. Review the text yourself before publishing.",
            possibleContact: "Possible contact information or credentials found. Remove private details from public text.",
            noName: "Enter an item name first.",
        },
        sw: {
            category: "Kategoria inayopendekezwa: ",
            noCategory: "Hakuna kategoria iliyo wazi; tafadhali chagua mwenyewe.",
            generated: "Rasimu ya msingi imeongezwa. Ihariri na ujumuishe maelezo unayoweza kuthibitisha pekee.",
            moderation: "Hakuna maelezo ya kawaida ya mawasiliano au maneno ya siri yaliyopatikana. Kagua maandishi mwenyewe kabla ya kuchapisha.",
            possibleContact: "Huenda maandishi yana maelezo ya mawasiliano au siri. Ondoa maelezo binafsi kwenye maandishi ya umma.",
            noName: "Andika jina la kifaa kwanza.",
        },
    }[language];
    const itemName = document.getElementById("item_name");
    const description = document.querySelector("[data-smart-description]");
    const category = document.getElementById("category");
    const assistantFeedback = document.querySelector("[data-assistant-feedback]");
    const categoryFeedback = document.querySelector("[data-category-feedback]");

    const normalize = (value) => value.toLocaleLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
    const categoryKeywords = {
        Electronics: ["phone", "mobile", "laptop", "computer", "tablet", "headphone", "charger", "simu", "rununu", "kompyuta"],
        Documents: ["id", "passport", "document", "card", "license", "kitambulisho", "pasipoti", "kadi"],
        Clothing: ["shirt", "jacket", "coat", "shoe", "sweater", "nguo", "shati", "kiatu"],
        Accessories: ["bag", "wallet", "keys", "watch", "glasses", "mkoba", "pochi", "funguo", "saa"],
        Books: ["book", "notebook", "textbook", "kitabu", "daftari"],
    };
    const suggestCategory = () => {
        const name = normalize(itemName.value);
        for (const option of category.options) {
            const mapping = Object.entries(categoryKeywords).find(([key]) => normalize(key) === normalize(option.value));
            if (mapping && mapping[1].some((keyword) => new RegExp(`(^|\\s)${keyword}($|\\s)`, "i").test(name))) {
                return option;
            }
        }
        return null;
    };
    document.querySelector("[data-suggest-category]")?.addEventListener("click", () => {
        const suggestion = suggestCategory();
        if (!suggestion) {
            categoryFeedback.textContent = itemName.value.trim() ? text.noCategory : text.noName;
            return;
        }
        category.value = suggestion.value;
        categoryFeedback.textContent = text.category + suggestion.textContent;
    });

    document.querySelector("[data-suggest-description]")?.addEventListener("click", () => {
        const name = itemName.value.trim();
        if (!name) {
            assistantFeedback.textContent = text.noName;
            return;
        }
        const status = document.getElementById("status").value === "found" ? "found" : "lost";
        const pieces = [];
        const location = document.getElementById("location").value.trim();
        const date = document.getElementById("date_lost_found").value.trim();
        if (location) pieces.push(`reported near ${location}`);
        if (date) pieces.push(`on ${date}`);
        description.value = `A ${status} ${name}${pieces.length ? `, ${pieces.join(" ")}` : ""}. Add distinguishing details you can verify; never include private ownership clues here.`;
        assistantFeedback.textContent = text.generated;
        description.dispatchEvent(new Event("input", { bubbles: true }));
    });

    document.querySelector("[data-check-moderation]")?.addEventListener("click", () => {
        const value = description.value;
        const possibleEmail = /\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i.test(value);
        const possiblePhone = /(?:\+?\d[\d\s().-]{7,}\d)/.test(value);
        const possibleSecret = /\b(password|pin|cvv|passcode)\b/i.test(value);
        assistantFeedback.textContent = possibleEmail || possiblePhone || possibleSecret ? text.possibleContact : text.moderation;
    });

    const currentPage = window.location.pathname.split("/").pop();
    const isAuthPage = currentPage === "login.php" || currentPage === "register.php";
    if (document.querySelector(".site-header") && !isAuthPage) {
        const help = document.createElement("aside");
        help.className = "local-help-assistant";
        const toggle = document.createElement("button");
        toggle.type = "button";
        toggle.className = "local-help-toggle";
        toggle.textContent = language === "sw" ? "Msaada" : "Help";
        toggle.setAttribute("aria-expanded", "false");
        toggle.setAttribute("aria-controls", "local-help-panel");
        const panel = document.createElement("section");
        panel.className = "local-help-panel";
        panel.id = "local-help-panel";
        panel.hidden = true;
        const title = document.createElement("h2");
        title.textContent = language === "sw" ? "Msaidizi wa msaada wa ndani" : "On-site help assistant";
        const transcript = document.createElement("div");
        transcript.className = "local-help-transcript";
        transcript.setAttribute("aria-live", "polite");
        transcript.setAttribute("aria-relevant", "additions");
        const form = document.createElement("form");
        form.className = "local-help-form";
        const input = document.createElement("input");
        input.type = "text";
        input.name = "question";
        input.maxLength = 500;
        input.autocomplete = "off";
        input.placeholder = language === "sw" ? "Andika swali lako..." : "Type your question...";
        input.setAttribute("aria-label", language === "sw" ? "Andika ujumbe wako" : "Type your message");
        const send = document.createElement("button");
        send.type = "submit";
        send.textContent = language === "sw" ? "Tuma" : "Send";
        const addMessage = (role, value) => {
            const message = document.createElement("p");
            message.className = `local-help-message local-help-message-${role}`;
            message.textContent = value;
            transcript.append(message);
            transcript.scrollTop = transcript.scrollHeight;
        };
        const answers = language === "sw"
            ? [
                ["ripoti|poteza|pata|tangaza", "Ingia na ufungue ukurasa wa kuripoti. Jaza aina, jina, maelezo ya umma na eneo. Kwa bidhaa iliyopatikana, weka njia ya faragha ya kuwasiliana nawe. Usichapishe kidokezo cha siri cha mmiliki."],
                ["dai|mmiliki|umiliki|claim", "Fungua tangazo la kifaa kilichopatikana na uchague Dai Kifaa. Toa maelezo ya siri yanayothibitisha umiliki; msimamizi atakagua dai.", "found_items.php"],
                ["eneo|ramani|makabidhiano|finder|mawasiliano", "Maelezo ya makabidhiano ni ya faragha na huonekana kwa mdai aliyeidhinishwa pekee. Panga kukutana mahali salama pa umma inapofaa."],
                ["nenosiri|ingia|akaunti|sajili|jisajili", "Ingia au unda akaunti kupitia kurasa hizi. Unaweza kutumia kiungo cha kusahau nenosiri ikiwa huwezi kuingia.", "login.php"],
                ["tafuta|linganisha|fanana|mechi", "Tumia kisanduku cha utafutaji kwenye ukurasa wa mwanzo. Mapendekezo ya ulinganisho ni msaada tu, si uthibitisho wa umiliki.", "index.php"],
                ["mipangilio|lugha|taarifa|faragha|data", "Unaweza kurekebisha mapendeleo ya akaunti na arifa katika mipangilio. Kwa masuala ya faragha au usaidizi zaidi, wasiliana na msimamizi.", "settings.php"],
            ]
            : [
                ["report|lost|found|list|submit", "Sign in and open the report form. Add the item type, name, public description, and location. For a found item, provide a private way for the owner to reach you. Keep private ownership clues out of the public description.", "report_item.php"],
                ["claim|owner|ownership|prove", "Open an active found-item listing and choose Claim This Item. Give a private ownership detail; an administrator reviews the claim.", "found_items.php"],
                ["handover|location|map|contact|reach|finder|phone", "Handover contact details and a pinned location stay private. They are shown only to the claimant after an administrator approves the claim. Arrange handovers safely.", "terms.php"],
                ["password|login|sign in|account|register|sign up", "You can sign in or create an account here. If you cannot access your account, use the password-recovery link on the sign-in page.", "login.php"],
                ["search|match|similar|find", "Use the search box on the home page to search by item name, details, category, or location. Match suggestions are only a guide, not proof of ownership.", "index.php"],
                ["settings|language|notification|privacy|data|delete", "Account preferences and notification settings are available in Settings. For a privacy request or other account help, contact the site administrator.", "settings.php"],
                ["admin|moderation|fraud|suspend", "Administrators can review claims, manage listings and accounts, and view activity records from the administration pages. Assistant suggestions do not make claim decisions.", "admin.php"],
            ];
        panel.append(title);
        form.append(input, send);
        panel.append(transcript, form);
        addMessage("assistant", language === "sw"
            ? "Habari! Unaweza kuuliza swali kwa maneno yako mwenyewe."
            : "Hi! Ask me a question in your own words.");
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            const question = input.value.trim();
            if (!question) return;
            addMessage("user", question);
            const normalizedQuestion = normalize(question);
            const matchedAnswer = answers.find(([keywords]) =>
                keywords.split("|").some((keyword) => normalizedQuestion.includes(normalize(keyword)))
            );
            if (!matchedAnswer) {
                addMessage("assistant", language === "sw"
                    ? "Samahani, sijapata jibu la uhakika. Jaribu kuuliza kuhusu kuripoti, madai, makabidhiano, kuingia, utafutaji au mipangilio."
                    : "I’m not sure about that yet. Try asking about reports, claims, handovers, sign-in, searching, or settings.");
            } else {
                addMessage("assistant", matchedAnswer[1]);
                matchedAnswer.slice(2).forEach((href) => {
                    if (!href) return;
                    const link = document.createElement("a");
                    link.href = href;
                    link.textContent = href === "found_items.php" ? "Browse found items"
                        : href === "report_item.php" ? "Report an item"
                        : href === "login.php" ? "Sign in"
                        : href === "settings.php" ? "Settings"
                        : href === "admin.php" ? "Admin dashboard"
                        : href === "terms.php" ? "Terms and privacy"
                        : "Home page";
                    link.className = "local-help-link";
                    transcript.append(link);
                });
                transcript.scrollTop = transcript.scrollHeight;
            }
            input.value = "";
            input.focus();
        });
        toggle.addEventListener("click", () => {
            const open = toggle.getAttribute("aria-expanded") !== "true";
            toggle.setAttribute("aria-expanded", String(open));
            panel.hidden = !open;
            if (open) input.focus();
        });
        help.append(toggle, panel);
        document.body.append(help);
    }
});
