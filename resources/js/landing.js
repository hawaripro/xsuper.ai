function enhanceLanding() {
    const body = document.body;
    if (!body) return;

    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
    const finePointer = window.matchMedia("(hover: hover) and (pointer: fine)");
    const mobileViewport = window.matchMedia("(max-width: 767px)");
    const menuToggle = document.getElementById("site-menu-toggle");
    const navigation = document.getElementById("site-nav");
    const themeToggle = document.getElementById("theme-toggle");
    const orbitStage = document.querySelector("[data-orbit-stage]");
    const status = document.getElementById("site-status");
    const zones = new Set(document.querySelectorAll("[data-motion-zone]"));
    const reveals = new Set(document.querySelectorAll("[data-reveal]"));
    const observedElements = new Set([...zones, ...reveals]);
    const revealedElements = new Set();
    const animations = new Map();
    const copyButtons = new Map();
    const purchaseRegion = document.querySelector("[data-purchase-region]");
    const purchaseDismiss = purchaseRegion?.querySelector(
        "[data-purchase-dismiss]",
    );
    const purchaseItems = purchaseRegion
        ? [...purchaseRegion.querySelectorAll("[data-purchase-item]")]
        : [];
    const removeListeners = [];
    const easing = "cubic-bezier(0.22, 1, 0.36, 1)";
    const themeStorageKey = "xsuper-theme";
    let pagePresent = true;
    let motionState = "paused";
    let menuOpen = false;
    let observing = false;
    let lifecycleVersion = 0;
    let statusTimer = 0;
    let purchaseTimer = 0;
    let purchaseIndex = 0;
    let purchaseDismissed = false;
    let purchaseDeadline = 0;
    let purchaseRemaining = 8000;
    let purchaseHovered = false;
    let purchaseFocused = false;
    let purchasePreviousFocus = null;
    let pointerFrame = 0;
    let pointerX = 0;
    let pointerY = 0;

    if (orbitStage) observedElements.add(orbitStage);

    const viewportObserver =
        "IntersectionObserver" in window
            ? new IntersectionObserver(handleIntersections, {
                  threshold: [0, 0.01],
              })
            : null;

    function listen(target, type, listener, options) {
        target.addEventListener(type, listener, options);
        removeListeners.push(() =>
            target.removeEventListener(type, listener, options),
        );
    }

    function listenMedia(query, listener) {
        if (query.addEventListener) {
            listen(query, "change", listener);
        } else {
            query.addListener(listener);
            removeListeners.push(() => query.removeListener(listener));
        }
    }

    function writeStorage(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch {
            // Storage can be unavailable in hardened/private contexts.
        }
    }
    try {
        window.localStorage.removeItem("xsuper-motion-paused");
    } catch {
        // Ignore storage restrictions; motion no longer reads this preference.
    }

    function syncTheme(theme) {
        const dark = theme === "dark";
        document.documentElement.classList.toggle("dark", dark);
        writeStorage(themeStorageKey, theme);
        const themeColor = document.querySelector('meta[name="theme-color"]');
        themeColor?.setAttribute("content", dark ? "#090d16" : "#f8faff");
        if (!themeToggle) return;
        themeToggle.hidden = false;
        themeToggle.setAttribute("aria-pressed", String(dark));
        themeToggle.setAttribute(
            "aria-label",
            dark
                ? themeToggle.dataset.lightLabel
                : themeToggle.dataset.darkLabel,
        );
    }

    let publicTheme = document.documentElement.classList.contains("dark")
        ? "dark"
        : "light";

    function pageIsVisible() {
        return pagePresent && !document.hidden;
    }

    function canAnimate(element) {
        if (
            motionState !== "running" ||
            !pageIsVisible() ||
            !viewportObserver ||
            typeof element.animate !== "function" ||
            !element.getClientRects().length
        ) {
            return false;
        }

        const rect = element.getBoundingClientRect();
        return (
            rect.bottom > 0 &&
            rect.top < window.innerHeight &&
            rect.right > 0 &&
            rect.left < window.innerWidth
        );
    }

    function releaseAnimation(element, animation) {
        if (animations.get(element) !== animation) return;
        animations.delete(element);
        if (!observedElements.has(element))
            viewportObserver?.unobserve(element);
    }

    function cancelAnimation(element) {
        const animation = animations.get(element);
        if (!animation) return;
        releaseAnimation(element, animation);
        animation.cancel();
    }

    function cancelAnimationsWithin(container) {
        for (const element of animations.keys()) {
            if (!container || container.contains(element))
                cancelAnimation(element);
        }
    }

    function animate(element, keyframes, options = {}) {
        cancelAnimation(element);
        if (!canAnimate(element)) return;

        let animation;
        try {
            // Backwards fill covers stagger delays; cancellation restores the visible SSR styles.
            animation = element.animate(keyframes, {
                duration: 560,
                easing,
                fill: "backwards",
                ...options,
            });
        } catch {
            return;
        }

        animations.set(element, animation);
        animation.onfinish = () => releaseAnimation(element, animation);
        animation.oncancel = () => releaseAnimation(element, animation);
        viewportObserver.observe(element);
    }

    function reveal(element) {
        if (revealedElements.has(element)) return;
        revealedElements.add(element);
        if (motionState !== "running") return;

        const rise = [
            { opacity: 0.25, transform: "translateY(22px)" },
            { opacity: 1, transform: "translateY(0)" },
        ];

        switch (element.dataset.reveal) {
            case "wipe":
                animate(
                    element,
                    [
                        { clipPath: "inset(0 100% 0 0)" },
                        { clipPath: "inset(0 0% 0 0)" },
                    ],
                    { duration: 680 },
                );
                break;
            case "line":
                animate(
                    element,
                    [
                        {
                            opacity: 0.35,
                            transform: "scaleX(0.12)",
                            transformOrigin: "left center",
                        },
                        {
                            opacity: 1,
                            transform: "scaleX(1)",
                            transformOrigin: "left center",
                        },
                    ],
                    { duration: 640 },
                );
                break;
            case "stagger": {
                const items = [
                    ...element.querySelectorAll("[data-reveal-item]"),
                ];
                if (!items.length) {
                    animate(element, rise);
                    break;
                }
                const step =
                    items.length > 1
                        ? Math.min(80, 400 / (items.length - 1))
                        : 0;
                items.forEach((item, index) =>
                    animate(item, rise, {
                        duration: 480,
                        delay: index * step,
                    }),
                );
                break;
            }
            default:
                animate(element, rise);
        }
    }

    function handleIntersections(entries) {
        if (!pageIsVisible()) return;
        for (const entry of entries) {
            const element = entry.target;
            const inView = entry.isIntersecting && entry.intersectionRatio > 0;
            if (zones.has(element))
                element.classList.toggle("is-in-view", inView);
            if (inView) {
                if (reveals.has(element)) reveal(element);
            } else {
                cancelAnimationsWithin(element);
                if (orbitStage && element.contains(orbitStage)) resetPointer();
            }
        }
    }

    function syncMotion() {
        motionState = reducedMotion.matches
            ? "reduced"
            : !pageIsVisible()
              ? "paused"
              : "running";
        body.dataset.motion = motionState;

        if (motionState !== "running") {
            cancelAnimationsWithin();
            resetPointer();
        }

        if (!pageIsVisible()) {
            viewportObserver?.disconnect();
            observing = false;
            zones.forEach((zone) => zone.classList.remove("is-in-view"));
        } else if (viewportObserver && !observing) {
            observing = true;
            observedElements.forEach((element) =>
                viewportObserver.observe(element),
            );
        }
    }

    function setMenuOpen(open, restoreFocus = false) {
        if (!navigation || !menuToggle) return;
        menuOpen = open && mobileViewport.matches;
        navigation.classList.toggle("is-open", menuOpen);
        navigation.inert = mobileViewport.matches && !menuOpen;
        if (mobileViewport.matches && !menuOpen) {
            navigation.setAttribute("aria-hidden", "true");
        } else {
            navigation.removeAttribute("aria-hidden");
        }
        menuToggle.setAttribute("aria-expanded", String(menuOpen));
        menuToggle.setAttribute(
            "aria-label",
            menuOpen
                ? menuToggle.dataset.closeLabel
                : menuToggle.dataset.openLabel,
        );
        if (restoreFocus) menuToggle.focus({ preventScroll: true });
    }

    function enhanceTabs(containerId, tabAttribute, panelAttribute) {
        const container = document.getElementById(containerId);
        if (!container) return;
        const tabs = [...container.querySelectorAll(`button[${tabAttribute}]`)];
        const panelsByName = new Map(
            [...document.querySelectorAll(`[${panelAttribute}]`)].map(
                (panel) => [panel.getAttribute(panelAttribute), panel],
            ),
        );
        const panels = tabs.map((tab) =>
            panelsByName.get(tab.getAttribute(tabAttribute)),
        );
        if (
            tabs.length < 2 ||
            panels.some((panel) => !panel) ||
            new Set(panels).size !== tabs.length
        )
            return;

        let selectedIndex = tabs.findIndex(
            (tab) => tab.getAttribute("aria-selected") === "true",
        );
        if (selectedIndex < 0) selectedIndex = 0;

        function selectTab(index, focus = false, withMotion = true) {
            const changed = index !== selectedIndex;
            selectedIndex = index;
            tabs.forEach((tab, tabIndex) => {
                const selected = index === tabIndex;
                tab.setAttribute("aria-selected", String(selected));
                tab.tabIndex = selected ? 0 : -1;
                if (!selected) cancelAnimationsWithin(panels[tabIndex]);
                panels[tabIndex].hidden = !selected;
            });
            if (focus) tabs[index].focus({ preventScroll: true });
            if (changed && withMotion) {
                animate(
                    panels[index],
                    [
                        { opacity: 0.5, transform: "translateY(8px)" },
                        { opacity: 1, transform: "translateY(0)" },
                    ],
                    { duration: 260 },
                );
            }
        }

        container.setAttribute("role", "tablist");
        container.setAttribute("aria-orientation", "horizontal");
        tabs.forEach((tab, index) => {
            const panel = panels[index];
            if (!tab.id) tab.id = `${containerId}-tab-${index + 1}`;
            if (!panel.id) panel.id = `${containerId}-panel-${index + 1}`;
            tab.setAttribute("role", "tab");
            tab.setAttribute("aria-controls", panel.id);
            panel.setAttribute("role", "tabpanel");
            panel.setAttribute("aria-labelledby", tab.id);
            if (!panel.hasAttribute("tabindex")) panel.tabIndex = 0;
            listen(tab, "click", () => selectTab(index));
            listen(tab, "keydown", (event) => {
                let next;
                switch (event.key) {
                    case "ArrowRight":
                        next = (index + 1) % tabs.length;
                        break;
                    case "ArrowLeft":
                        next = (index - 1 + tabs.length) % tabs.length;
                        break;
                    case "Home":
                        next = 0;
                        break;
                    case "End":
                        next = tabs.length - 1;
                        break;
                    default:
                        return;
                }
                event.preventDefault();
                selectTab(next, true);
            });
        });
        selectTab(selectedIndex, false, false);
    }

    function resetPointer() {
        if (pointerFrame) window.cancelAnimationFrame(pointerFrame);
        pointerFrame = 0;
        if (!orbitStage) return;
        orbitStage.style.setProperty("--pointer-x", "0deg");
        orbitStage.style.setProperty("--pointer-y", "0deg");
    }

    function updatePointer() {
        pointerFrame = 0;
        if (!orbitStage || !finePointer.matches || !canAnimate(orbitStage)) {
            resetPointer();
            return;
        }
        const rect = orbitStage.getBoundingClientRect();
        if (!rect.width || !rect.height) return;
        const x = Math.max(
            -1,
            Math.min(1, ((pointerX - rect.left) / rect.width) * 2 - 1),
        );
        const y = Math.max(
            -1,
            Math.min(1, ((pointerY - rect.top) / rect.height) * 2 - 1),
        );
        orbitStage.style.setProperty(
            "--pointer-x",
            `${(x * 3.5).toFixed(2)}deg`,
        );
        orbitStage.style.setProperty(
            "--pointer-y",
            `${(y * 3.5).toFixed(2)}deg`,
        );
    }

    function clearStatus() {
        window.clearTimeout(statusTimer);
        statusTimer = 0;
        if (!status) return;
        status.classList.remove("has-message");
        status.textContent = "";
    }

    function announce(message) {
        if (!status || !pageIsVisible()) return;
        window.clearTimeout(statusTimer);
        status.textContent = message;
        status.classList.add("has-message");
        statusTimer = window.setTimeout(clearStatus, 6000);
    }

    function copyWithSelection(text) {
        if (typeof document.execCommand !== "function") return false;
        const textarea = document.createElement("textarea");
        const focused = document.activeElement;
        const selection = window.getSelection();
        const ranges = [];
        if (selection) {
            for (let index = 0; index < selection.rangeCount; index++) {
                ranges.push(selection.getRangeAt(index).cloneRange());
            }
        }
        textarea.value = text;
        textarea.readOnly = true;
        textarea.tabIndex = -1;
        textarea.setAttribute("aria-label", "Teks untuk disalin");
        textarea.style.cssText =
            "position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;pointer-events:none";
        let copied = false;
        try {
            body.appendChild(textarea);
            textarea.focus({ preventScroll: true });
            textarea.select();
            copied = document.execCommand("copy");
        } catch {
            copied = false;
        } finally {
            textarea.remove();
            if (focused?.isConnected && typeof focused.focus === "function") {
                focused.focus({ preventScroll: true });
            }
            if (selection) {
                selection.removeAllRanges();
                ranges.forEach((range) => selection.addRange(range));
            }
        }
        return copied;
    }

    async function copyText(text, isCurrent) {
        if (navigator.clipboard?.writeText) {
            try {
                await navigator.clipboard.writeText(text);
                return isCurrent() ? true : null;
            } catch {
                // A denied clipboard permission still permits a best-effort native copy.
            }
        }
        return isCurrent() ? copyWithSelection(text) : null;
    }

    function clearPurchaseTimer(preserveRemaining = false) {
        window.clearTimeout(purchaseTimer);
        purchaseTimer = 0;
        if (preserveRemaining && purchaseDeadline) {
            purchaseRemaining = Math.max(0, purchaseDeadline - performance.now());
        }
        purchaseDeadline = 0;
    }

    function showPurchase(index, withMotion = true) {
        if (!purchaseRegion || !purchaseItems.length || purchaseDismissed)
            return;

        purchaseIndex = index;
        purchaseItems.forEach((item, itemIndex) => {
            item.hidden = itemIndex !== index;
        });
        purchaseRegion.hidden = false;

        if (withMotion) {
            animate(
                purchaseRegion,
                [
                    { opacity: 0.35, transform: "translateY(10px)" },
                    { opacity: 1, transform: "translateY(0)" },
                ],
                { duration: 280 },
            );
        }
    }

    function scheduleNextPurchase() {
        clearPurchaseTimer(true);
        if (
            purchaseDismissed ||
            reducedMotion.matches ||
            purchaseHovered ||
            purchaseFocused ||
            purchaseItems.length < 2 ||
            purchaseIndex >= purchaseItems.length - 1 ||
            !pageIsVisible()
        )
            return;

        const delay = Math.max(0, purchaseRemaining);
        purchaseDeadline = performance.now() + delay;
        purchaseTimer = window.setTimeout(() => {
            purchaseTimer = 0;
            if (!pageIsVisible()) {
                clearPurchaseTimer(true);
                return;
            }
            purchaseDeadline = 0;
            purchaseRemaining = 8000;
            showPurchase(purchaseIndex + 1);
            scheduleNextPurchase();
        }, delay);
    }

    function dismissPurchases() {
        purchaseDismissed = true;
        clearPurchaseTimer();
        if (purchaseRegion) {
            const restoreFocus = purchaseRegion.contains(document.activeElement);
            cancelAnimation(purchaseRegion);
            purchaseRegion.hidden = true;
            if (restoreFocus && purchasePreviousFocus?.isConnected) {
                purchasePreviousFocus.focus({ preventScroll: true });
            }
        }
    }

    if (purchaseRegion && purchaseDismiss && purchaseItems.length) {
        showPurchase(0, false);
        scheduleNextPurchase();
        listen(purchaseDismiss, "click", dismissPurchases);
        listen(purchaseRegion, "pointerenter", () => {
            purchaseHovered = true;
            clearPurchaseTimer(true);
        });
        listen(purchaseRegion, "pointerleave", () => {
            purchaseHovered = false;
            scheduleNextPurchase();
        });
        listen(purchaseRegion, "focusin", (event) => {
            purchaseFocused = true;
            if (!purchaseRegion.contains(event.relatedTarget)) {
                purchasePreviousFocus = event.relatedTarget;
            }
            clearPurchaseTimer(true);
        });
        listen(purchaseRegion, "focusout", (event) => {
            purchaseFocused = purchaseRegion.contains(event.relatedTarget);
            if (!purchaseFocused) scheduleNextPurchase();
        });
        listen(purchaseRegion, "keydown", (event) => {
            if (event.key !== "Escape") return;
            event.preventDefault();
            dismissPurchases();
        });
    }

    function clearTransientWork() {
        lifecycleVersion++;
        cancelAnimationsWithin();
        resetPointer();
        clearStatus();
        clearPurchaseTimer(true);
        copyButtons.forEach((disabled, button) => {
            button.disabled = disabled;
            button.removeAttribute("aria-busy");
        });
    }

    if (navigation && menuToggle) {
        menuToggle.setAttribute("aria-controls", navigation.id);
        setMenuOpen(false);
        listen(menuToggle, "click", () => setMenuOpen(!menuOpen));
        listen(navigation, "click", (event) => {
            const anchor =
                event.target instanceof Element
                    ? event.target.closest("a[href]")
                    : null;
            if (
                anchor?.hash &&
                anchor.origin === window.location.origin &&
                anchor.pathname === window.location.pathname &&
                anchor.search === window.location.search
            ) {
                setMenuOpen(false);
            }
        });
        listen(document, "click", (event) => {
            if (
                menuOpen &&
                event.target instanceof Node &&
                !navigation.contains(event.target) &&
                !menuToggle.contains(event.target)
            ) {
                setMenuOpen(false);
            }
        });
        listen(document, "keydown", (event) => {
            if (event.key === "Escape" && menuOpen) {
                event.preventDefault();
                setMenuOpen(false, true);
            }
        });
        listenMedia(mobileViewport, () => setMenuOpen(false));
        menuToggle.hidden = false;
    }

    const pricingTickets = [
        ...document.querySelectorAll("[data-pricing-ticket]"),
    ];
    const ribbonLabel = document.getElementById("ribbon-plan-label");
    const ribbonPrice = document.getElementById("ribbon-plan-price");
    const ribbonDaily = document.getElementById("ribbon-plan-daily");
    if (pricingTickets.length && ribbonLabel && ribbonPrice && ribbonDaily) {
        const selectTicket = (ticket, focus = false) => {
            pricingTickets.forEach((item) => {
                const selected = item === ticket;
                item.classList.toggle("is-selected", selected);
                item.setAttribute("aria-pressed", String(selected));
            });
            ribbonLabel.textContent = ticket.dataset.planLabel;
            ribbonPrice.textContent = ticket.dataset.planPrice;
            ribbonDaily.textContent = ticket.dataset.planDaily;
            if (focus) ticket.focus({ preventScroll: true });
        };
        pricingTickets.forEach((ticket, index) => {
            listen(ticket, "click", () => selectTicket(ticket));
            listen(ticket, "keydown", (event) => {
                if (
                    !["ArrowLeft", "ArrowRight", "Home", "End"].includes(
                        event.key,
                    )
                )
                    return;
                event.preventDefault();
                const next =
                    event.key === "Home"
                        ? 0
                        : event.key === "End"
                          ? pricingTickets.length - 1
                          : (index +
                                (event.key === "ArrowRight" ? 1 : -1) +
                                pricingTickets.length) %
                            pricingTickets.length;
                selectTicket(pricingTickets[next], true);
            });
        });
    }

    enhanceTabs("demo-tabs", "data-demo-tab", "data-demo-panel");
    enhanceTabs("api-tabs", "data-code-tab", "data-code-panel");

    const orbitButtons = [
        ...document.querySelectorAll("button[data-orbit-model]"),
    ];
    const modelName = document.getElementById("orbit-model-name");
    const modelDescription = document.getElementById("orbit-description");
    if (modelName && modelDescription && orbitButtons.length) {
        let selectedButton;
        function selectModel(button) {
            if (
                selectedButton === button ||
                !button.dataset.modelName ||
                !button.dataset.modelDescription
            )
                return;
            selectedButton = button;
            orbitButtons.forEach((candidate) => {
                const selected = candidate === button;
                candidate.setAttribute("aria-pressed", String(selected));
                candidate.classList.toggle("is-selected", selected);
            });
            modelName.textContent = button.dataset.modelName;
            modelDescription.textContent = button.dataset.modelDescription;
        }
        orbitButtons.forEach((button) => {
            listen(button, "click", () => selectModel(button));
            listen(button, "focus", () => selectModel(button));
        });
        selectModel(
            orbitButtons.find(
                (button) => button.getAttribute("aria-pressed") === "true",
            ) || orbitButtons[0],
        );
    }

    if (orbitStage) {
        resetPointer();
        listen(
            orbitStage,
            "pointermove",
            (event) => {
                if (
                    !finePointer.matches ||
                    motionState !== "running" ||
                    event.pointerType === "touch"
                )
                    return;
                pointerX = event.clientX;
                pointerY = event.clientY;
                if (!pointerFrame)
                    pointerFrame = window.requestAnimationFrame(updatePointer);
            },
            { passive: true },
        );
        listen(orbitStage, "pointerleave", resetPointer);
        listen(orbitStage, "pointercancel", resetPointer);
        listen(window, "blur", resetPointer);
        listenMedia(finePointer, resetPointer);
    }

    document.querySelectorAll("#faq details").forEach((details) => {
        const answer = details.querySelector(".faq-answer");
        if (!answer) return;
        listen(details, "toggle", () => {
            cancelAnimationsWithin(answer);
            if (!details.open || !canAnimate(answer)) return;
            const height = answer.getBoundingClientRect().height;
            animate(
                answer,
                [
                    {
                        height: "0px",
                        opacity: 0.35,
                        overflow: "hidden",
                        boxSizing: "border-box",
                    },
                    {
                        height: `${height}px`,
                        opacity: 1,
                        overflow: "hidden",
                        boxSizing: "border-box",
                    },
                ],
                { duration: 260 },
            );
        });
    });

    if (themeToggle) {
        syncTheme(publicTheme);
        listen(themeToggle, "click", () => {
            publicTheme = publicTheme === "dark" ? "light" : "dark";
            syncTheme(publicTheme);
        });
    }

    if (status) {
        status.setAttribute("role", "status");
        status.setAttribute("aria-live", "polite");
        status.setAttribute("aria-atomic", "true");
        document
            .querySelectorAll("button[data-copy-target]")
            .forEach((button) => {
                const target = document.getElementById(
                    button.dataset.copyTarget,
                );
                if (!target) return;
                copyButtons.set(button, button.disabled);
                listen(button, "click", async () => {
                    if (button.disabled || !pageIsVisible()) return;
                    const version = lifecycleVersion;
                    const isCurrent = () =>
                        version === lifecycleVersion && pageIsVisible();
                    button.disabled = true;
                    button.setAttribute("aria-busy", "true");
                    try {
                        const copied = await copyText(
                            target.textContent ?? "",
                            isCurrent,
                        );
                        if (isCurrent()) {
                            announce(
                                copied
                                    ? body.dataset.copySuccess
                                    : body.dataset.copyFailure,
                            );
                        }
                    } finally {
                        if (version === lifecycleVersion) {
                            button.disabled = copyButtons.get(button);
                            button.removeAttribute("aria-busy");
                        }
                    }
                });
                button.hidden = false;
            });
    }

    const modelSearch = document.querySelector("[data-model-search]");
    const modelCards = [...document.querySelectorAll("[data-model-card]")];
    const modelCount = document.querySelector("[data-model-count]");
    const modelEmpty = document.querySelector("[data-model-empty]");
    const categoryButtons = [
        ...document.querySelectorAll("[data-model-category]"),
    ];
    const modalityChecks = [
        ...document.querySelectorAll("[data-model-modality-check]"),
    ];
    const capabilityChecks = [
        ...document.querySelectorAll("[data-model-capability-check]"),
    ];
    const providerChecks = [
        ...document.querySelectorAll("[data-model-provider-check]"),
    ];
    const billingChecks = [
        ...document.querySelectorAll("[data-model-billing-check]"),
    ];
    const contextChecks = [
        ...document.querySelectorAll("[data-model-context-check]"),
    ];
    const clearModels = document.querySelector("[data-model-clear]");
    if (modelCards.length) {
        let category = "";
        const checked = (controls) =>
            controls
                .filter((control) => control.checked)
                .map((control) => control.value);
        const filterModels = () => {
            const query = modelSearch?.value.trim().toLowerCase() || "";
            const modalities = checked(modalityChecks);
            const capabilities = checked(capabilityChecks);
            const providers = checked(providerChecks);
            const billings = checked(billingChecks);
            const minimumContext = Number(
                contextChecks.find((control) => control.checked)?.value || 0,
            );
            let visible = 0;
            modelCards.forEach((card) => {
                const cardCapabilities =
                    card.dataset.modelCapabilities.split(/\s+/);
                const cardModalities =
                    card.dataset.modelModalities.split(/\s+/);
                const matches =
                    (!query || card.dataset.modelSearchText.includes(query)) &&
                    (!category || cardModalities.includes(category)) &&
                    (!modalities.length ||
                        modalities.every((value) =>
                            cardModalities.includes(value),
                        )) &&
                    (!capabilities.length ||
                        capabilities.every((value) =>
                            cardCapabilities.includes(value),
                        )) &&
                    (!providers.length ||
                        providers.includes(card.dataset.modelProvider)) &&
                    (!billings.length ||
                        billings.includes(card.dataset.modelBilling)) &&
                    (!minimumContext ||
                        Number(card.dataset.modelContext) >= minimumContext);
                card.hidden = !matches;
                if (matches) visible += 1;
            });
            if (modelCount) modelCount.textContent = String(visible);
            if (modelEmpty) modelEmpty.hidden = visible !== 0;
        };
        categoryButtons.forEach((button) =>
            listen(button, "click", () => {
                category = button.dataset.modelCategory;
                categoryButtons.forEach((candidate) =>
                    candidate.classList.toggle(
                        "is-active",
                        candidate === button,
                    ),
                );
                filterModels();
            }),
        );
        [
            modelSearch,
            ...modalityChecks,
            ...capabilityChecks,
            ...providerChecks,
            ...billingChecks,
            ...contextChecks,
        ]
            .filter(Boolean)
            .forEach((control) => listen(control, "input", filterModels));
        if (clearModels)
            listen(clearModels, "click", () => {
                if (modelSearch) modelSearch.value = "";
                [
                    ...modalityChecks,
                    ...capabilityChecks,
                    ...providerChecks,
                    ...billingChecks,
                ].forEach((control) => {
                    control.checked = false;
                });
                contextChecks.forEach((control, index) => {
                    control.checked = index === 0;
                });
                category = "";
                categoryButtons.forEach((button, index) =>
                    button.classList.toggle("is-active", index === 0),
                );
                filterModels();
            });
        const filterToggle = document.querySelector(
            "[data-model-filter-toggle]",
        );
        const filterSidebar = document.querySelector(
            "[data-model-filter-sidebar]",
        );
        const filterBackdrop = document.querySelector(
            "[data-model-filter-backdrop]",
        );
        if (filterToggle && filterSidebar) {
            const setFilterOpen = (open) => {
                filterSidebar.classList.toggle("is-open", open);
                filterToggle.setAttribute("aria-expanded", String(open));
                body.classList.toggle("model-filter-open", open);
                if (filterBackdrop) filterBackdrop.hidden = !open;
                if (open) {
                    const focusTarget =
                        filterSidebar.querySelector("input, button");
                    if (focusTarget) focusTarget.focus({ preventScroll: true });
                }
            };
            listen(filterToggle, "click", () =>
                setFilterOpen(
                    !filterSidebar.classList.contains("is-open"),
                ),
            );
            if (filterBackdrop)
                listen(filterBackdrop, "click", () => setFilterOpen(false));
            listen(document, "keydown", (event) => {
                if (
                    event.key === "Escape" &&
                    filterSidebar.classList.contains("is-open")
                ) {
                    setFilterOpen(false);
                    filterToggle.focus({ preventScroll: true });
                }
            });
            listen(window, "resize", () => {
                if (
                    window.innerWidth > 900 &&
                    filterSidebar.classList.contains("is-open")
                )
                    setFilterOpen(false);
            });
        }
        document.querySelectorAll("[data-copy-model]").forEach((button) =>
            listen(button, "click", async () => {
                await copyText(button.dataset.copyModel, () => pageIsVisible());
                announce(body.dataset.copySuccess);
            }),
        );
    }

    const pricingCarousel = document.querySelector("[data-pricing-carousel]");
    const pricingTrack = pricingCarousel?.querySelector(".pricing-grid-page");
    const pricingPrev = document.querySelector("[data-pricing-prev]");
    const pricingNext = document.querySelector("[data-pricing-next]");
    if (pricingTrack && pricingPrev && pricingNext) {
        const rotatePlans = (direction) => {
            const cards = [...pricingTrack.children];
            if (cards.length < 2) return;
            if (direction > 0) pricingTrack.append(cards[0]);
            else pricingTrack.prepend(cards[cards.length - 1]);
            pricingTrack.animate(
                [
                    {
                        transform: `translateX(${direction > 0 ? "18px" : "-18px"})`,
                        opacity: 0.82,
                    },
                    { transform: "translateX(0)", opacity: 1 },
                ],
                {
                    duration: reducedMotion.matches ? 0 : 260,
                    easing: "cubic-bezier(.16,1,.3,1)",
                },
            );
        };
        listen(pricingPrev, "click", () => rotatePlans(-1));
        listen(pricingNext, "click", () => rotatePlans(1));
    }

    listenMedia(reducedMotion, () => {
        syncMotion();
        scheduleNextPurchase();
    });
    listen(document, "visibilitychange", () => {
        if (document.hidden) clearTransientWork();
        syncMotion();
        if (!document.hidden) scheduleNextPurchase();
    });
    listen(window, "pagehide", (event) => {
        pagePresent = false;
        clearTransientWork();
        setMenuOpen(false);
        syncMotion();
        if (!event.persisted) {
            removeListeners.forEach((remove) => remove());
            removeListeners.length = 0;
        }
    });
    listen(window, "pageshow", () => {
        pagePresent = true;
        publicTheme = document.documentElement.classList.contains("dark")
            ? "dark"
            : "light";
        syncTheme(publicTheme);
        setMenuOpen(false);
        syncMotion();
        scheduleNextPurchase();
    });

    setupAnnouncementMarquee();
    syncMotion();
    document.documentElement.classList.add("js");
}

// Match the dashboard ribbon: constant 70px/s scroll and enough repeated copies
// to fill the viewport so a short message never leaves a gap before it loops.
function setupAnnouncementMarquee() {
    const aside = document.querySelector(".site-announcement");
    const track = aside && aside.querySelector(".site-announcement-track");
    const first = track && track.querySelector(".site-announcement-copy");
    if (!aside || !track || !first) return;
    const SPEED = 70;
    const template = first.cloneNode(true);
    template.removeAttribute("aria-hidden");
    let building = false;
    const build = () => {
        if (building) return;
        const reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
        if (reduce) { track.style.removeProperty("--site-announcement-duration"); return; }
        const probe = template.cloneNode(true);
        probe.style.visibility = "hidden";
        probe.style.position = "absolute";
        track.appendChild(probe);
        const unit = probe.getBoundingClientRect().width;
        track.removeChild(probe);
        const container = aside.getBoundingClientRect().width;
        if (!unit || !container) return;
        const copies = Math.max(1, Math.ceil(container / unit) + 1);
        building = true;
        const fragment = document.createDocumentFragment();
        for (let group = 0; group < 2; group += 1) {
            for (let index = 0; index < copies; index += 1) {
                const copy = template.cloneNode(true);
                if (group === 1) copy.setAttribute("aria-hidden", "true");
                fragment.appendChild(copy);
            }
        }
        track.replaceChildren(fragment);
        track.style.setProperty("--site-announcement-duration", Math.max(12, Math.round((copies * unit) / SPEED)) + "s");
        building = false;
    };
    build();
    if (typeof ResizeObserver === "function") {
        new ResizeObserver(() => build()).observe(aside);
    }
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", enhanceLanding, {
        once: true,
    });
} else {
    enhanceLanding();
}
