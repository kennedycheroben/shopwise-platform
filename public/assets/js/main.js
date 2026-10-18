/**
 * Template Name: ShopWise
 * Template URL: https://bootstrapmade.com/shopwise-bootstrap-ecommerce-template/
 * Updated: Apr 08 2026 with Bootstrap v5.3.8
 * Author: BootstrapMade.com
 * License: https://bootstrapmade.com/license/
 */

(function() {
    "use strict";

    /**
     * Apply .scrolled class to the body as the page is scrolled down
     */
    function toggleScrolled() {
        const selectBody = document.querySelector('body');
        const selectHeader = document.querySelector('#header');
        if (!selectHeader.classList.contains('scroll-up-sticky') && !selectHeader.classList.contains('sticky-top') && !selectHeader.classList.contains('fixed-top')) return;
        window.scrollY > 100 ? selectBody.classList.add('scrolled') : selectBody.classList.remove('scrolled');
    }

    document.addEventListener('scroll', toggleScrolled);
    window.addEventListener('load', toggleScrolled);

    /**
     * Scroll up sticky header to headers with .scroll-up-sticky class
     */
    let lastScrollTop = 0;
    window.addEventListener('scroll', function() {
        const selectHeader = document.querySelector('#header');
        if (!selectHeader.classList.contains('scroll-up-sticky')) return;

        let scrollTop = window.pageYOffset || document.documentElement.scrollTop;

        if (scrollTop > lastScrollTop && scrollTop > selectHeader.offsetHeight) {
            selectHeader.style.setProperty('position', 'sticky', 'important');
            selectHeader.style.top = `-${header.offsetHeight + 50}px`;
        } else if (scrollTop > selectHeader.offsetHeight) {
            selectHeader.style.setProperty('position', 'sticky', 'important');
            selectHeader.style.top = "0";
        } else {
            selectHeader.style.removeProperty('top');
            selectHeader.style.removeProperty('position');
        }
        lastScrollTop = scrollTop;
    });

    /**
     * Init swiper sliders
     */
    function initSwiper() {
        document.querySelectorAll(".init-swiper").forEach(function(swiperElement) {
            let config = JSON.parse(
                swiperElement.querySelector(".swiper-config").innerHTML.trim()
            );

            if (swiperElement.classList.contains("swiper-tab")) {
                initSwiperWithCustomPagination(swiperElement, config);
            } else {
                new Swiper(swiperElement, config);
            }
        });
    }

    window.addEventListener("load", initSwiper);

    /**
     * Mobile nav toggle
     */
    const mobileNavToggleBtn = document.querySelector('.mobile-nav-toggle');

    function mobileNavToogle() {
        document.querySelector('body').classList.toggle('mobile-nav-active');
        mobileNavToggleBtn.classList.toggle('bi-list');
        mobileNavToggleBtn.classList.toggle('bi-x');
    }
    if (mobileNavToggleBtn) {
        mobileNavToggleBtn.addEventListener('click', mobileNavToogle);
    }

    /**
     * Hide mobile nav on same-page/hash links
     */
    document.querySelectorAll('#navmenu a').forEach(navmenu => {
        navmenu.addEventListener('click', () => {
            if (document.querySelector('.mobile-nav-active') && !navmenu.classList.contains('toggle-dropdown')) {
                mobileNavToogle();
            }
        });

    });

    /**
     * Toggle mobile nav dropdowns
     */
    document.querySelectorAll('.navmenu .toggle-dropdown').forEach(navmenu => {
        navmenu.addEventListener('click', function(e) {
            e.preventDefault();
            this.parentNode.classList.toggle('active');
            this.parentNode.nextElementSibling.classList.toggle('dropdown-active');
            e.stopImmediatePropagation();
        });
    });

    /**
     * Scroll top button
     */
    let scrollTop = document.querySelector('.scroll-top');

    function toggleScrollTop() {
        if (scrollTop) {
            window.scrollY > 100 ? scrollTop.classList.add('active') : scrollTop.classList.remove('active');
        }
    }
    scrollTop.addEventListener('click', (e) => {
        e.preventDefault();
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    });

    window.addEventListener('load', toggleScrollTop);
    document.addEventListener('scroll', toggleScrollTop);

    /**
     * Countdown timer
     */
    function updateCountDown(countDownItem) {
        const timeleft = new Date(countDownItem.getAttribute('data-count')).getTime() - new Date().getTime();

        const days = Math.floor(timeleft / (1000 * 60 * 60 * 24));
        const hours = Math.floor((timeleft % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const minutes = Math.floor((timeleft % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((timeleft % (1000 * 60)) / 1000);

        const daysElement = countDownItem.querySelector('.count-days');
        const hoursElement = countDownItem.querySelector('.count-hours');
        const minutesElement = countDownItem.querySelector('.count-minutes');
        const secondsElement = countDownItem.querySelector('.count-seconds');

        if (daysElement) daysElement.innerHTML = days;
        if (hoursElement) hoursElement.innerHTML = hours;
        if (minutesElement) minutesElement.innerHTML = minutes;
        if (secondsElement) secondsElement.innerHTML = seconds;

    }

    document.querySelectorAll('.countdown').forEach(function(countDownItem) {
        updateCountDown(countDownItem);
        setInterval(function() {
            updateCountDown(countDownItem);
        }, 1000);
    });

    /**
     * Product Image Zoom and Thumbnail Functionality
     */

    function productDetailFeatures() {
        // Initialize Drift for image zoom
        function initDriftZoom() {
            // Check if Drift is available
            if (typeof Drift === 'undefined') {
                console.error('Drift library is not loaded');
                return;
            }

            const driftOptions = {
                paneContainer: document.querySelector('.image-zoom-container'),
                inlinePane: window.innerWidth < 768 ? true : false,
                inlineOffsetY: -85,
                containInline: true,
                hoverBoundingBox: false,
                zoomFactor: 3,
                handleTouch: false
            };

            // Initialize Drift on the main product image
            const mainImage = document.getElementById('main-product-image');
            if (mainImage) {
                new Drift(mainImage, driftOptions);
            }
        }

        // Thumbnail click functionality
        function initThumbnailClick() {
            const thumbnails = document.querySelectorAll('.thumbnail-item');
            const mainImage = document.getElementById('main-product-image');

            if (!thumbnails.length || !mainImage) return;

            thumbnails.forEach(thumbnail => {
                thumbnail.addEventListener('click', function() {
                    // Get image path from data attribute
                    const imageSrc = this.getAttribute('data-image');

                    // Update main image src and zoom attribute
                    mainImage.src = imageSrc;
                    mainImage.setAttribute('data-zoom', imageSrc);

                    // Update active state
                    thumbnails.forEach(item => item.classList.remove('active'));
                    this.classList.add('active');

                    // Reinitialize Drift for the new image
                    initDriftZoom();
                });
            });
        }

        // Image navigation functionality (prev/next buttons)
        function initImageNavigation() {
            const prevButton = document.querySelector('.image-nav-btn.prev-image');
            const nextButton = document.querySelector('.image-nav-btn.next-image');

            if (!prevButton || !nextButton) return;

            const thumbnails = Array.from(document.querySelectorAll('.thumbnail-item'));
            if (!thumbnails.length) return;

            // Function to navigate to previous or next image
            function navigateImage(direction) {
                // Find the currently active thumbnail
                const activeIndex = thumbnails.findIndex(thumb => thumb.classList.contains('active'));
                if (activeIndex === -1) return;

                let newIndex;
                if (direction === 'prev') {
                    // Go to previous image or loop to the last one
                    newIndex = activeIndex === 0 ? thumbnails.length - 1 : activeIndex - 1;
                } else {
                    // Go to next image or loop to the first one
                    newIndex = activeIndex === thumbnails.length - 1 ? 0 : activeIndex + 1;
                }

                // Simulate click on the new thumbnail
                thumbnails[newIndex].click();
            }

            // Add event listeners to navigation buttons
            prevButton.addEventListener('click', () => navigateImage('prev'));
            nextButton.addEventListener('click', () => navigateImage('next'));
        }

        // Initialize all features
        initDriftZoom();
        initThumbnailClick();
        initImageNavigation();
    }

    productDetailFeatures();

    /**
     * Ecommerce Cart Functionality
     * Handles quantity changes and item removal
     */

    function ecommerceCartTools() {
        // Get all quantity buttons and inputs directly
        const decreaseButtons = document.querySelectorAll('.quantity-btn.decrease');
        const increaseButtons = document.querySelectorAll('.quantity-btn.increase');
        const quantityInputs = document.querySelectorAll('.quantity-input');
        const removeButtons = document.querySelectorAll('.remove-item');

        // Decrease quantity buttons
        decreaseButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                const quantityInput = btn.closest('.quantity-selector').querySelector('.quantity-input');
                let currentValue = parseInt(quantityInput.value);
                if (currentValue > 1) {
                    quantityInput.value = currentValue - 1;
                }
            });
        });

        // Increase quantity buttons
        increaseButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                const quantityInput = btn.closest('.quantity-selector').querySelector('.quantity-input');
                let currentValue = parseInt(quantityInput.value);
                if (currentValue < parseInt(quantityInput.getAttribute('max'))) {
                    quantityInput.value = currentValue + 1;
                }
            });
        });

        // Manual quantity inputs
        quantityInputs.forEach(input => {
            input.addEventListener('change', function() {
                let currentValue = parseInt(input.value);
                const min = parseInt(input.getAttribute('min'));
                const max = parseInt(input.getAttribute('max'));

                // Validate input
                if (isNaN(currentValue) || currentValue < min) {
                    input.value = min;
                } else if (currentValue > max) {
                    input.value = max;
                }
            });
        });

        // Remove item buttons
        removeButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                btn.closest('.cart-item').remove();
            });
        });
    }

    ecommerceCartTools();

    /**
     * Price range slider implementation for price filtering.
     */
    function priceRangeWidget() {
        // Get all price range widgets on the page
        const priceRangeWidgets = document.querySelectorAll('.price-range-container');

        priceRangeWidgets.forEach(widget => {
            const minRange = widget.querySelector('.min-range');
            const maxRange = widget.querySelector('.max-range');
            const sliderProgress = widget.querySelector('.slider-progress');
            const minPriceDisplay = widget.querySelector('.current-range .min-price');
            const maxPriceDisplay = widget.querySelector('.current-range .max-price');
            const minPriceInput = widget.querySelector('.min-price-input');
            const maxPriceInput = widget.querySelector('.max-price-input');
            const applyButton = widget.querySelector('.filter-actions .btn-primary');

            if (!minRange || !maxRange || !sliderProgress || !minPriceDisplay || !maxPriceDisplay || !minPriceInput || !maxPriceInput) return;

            // Slider configuration
            const sliderMin = parseInt(minRange.min);
            const sliderMax = parseInt(minRange.max);
            const step = parseInt(minRange.step) || 1;

            // Initialize with default values
            let minValue = parseInt(minRange.value);
            let maxValue = parseInt(maxRange.value);

            // Set initial values
            updateSliderProgress();
            updateDisplays();

            // Min range input event
            minRange.addEventListener('input', function() {
                minValue = parseInt(this.value);

                // Ensure min doesn't exceed max
                if (minValue > maxValue) {
                    minValue = maxValue;
                    this.value = minValue;
                }

                // Update min price input and display
                minPriceInput.value = minValue;
                updateDisplays();
                updateSliderProgress();
            });

            // Max range input event
            maxRange.addEventListener('input', function() {
                maxValue = parseInt(this.value);

                // Ensure max isn't less than min
                if (maxValue < minValue) {
                    maxValue = minValue;
                    this.value = maxValue;
                }

                // Update max price input and display
                maxPriceInput.value = maxValue;
                updateDisplays();
                updateSliderProgress();
            });

            // Min price input change
            minPriceInput.addEventListener('change', function() {
                let value = parseInt(this.value) || sliderMin;

                // Ensure value is within range
                value = Math.max(sliderMin, Math.min(sliderMax, value));

                // Ensure min doesn't exceed max
                if (value > maxValue) {
                    value = maxValue;
                }

                // Update min value and range input
                minValue = value;
                this.value = value;
                minRange.value = value;
                updateDisplays();
                updateSliderProgress();
            });

            // Max price input change
            maxPriceInput.addEventListener('change', function() {
                let value = parseInt(this.value) || sliderMax;

                // Ensure value is within range
                value = Math.max(sliderMin, Math.min(sliderMax, value));

                // Ensure max isn't less than min
                if (value < minValue) {
                    value = minValue;
                }

                // Update max value and range input
                maxValue = value;
                this.value = value;
                maxRange.value = value;
                updateDisplays();
                updateSliderProgress();
            });

            // Apply button click
            if (applyButton) {
                applyButton.addEventListener('click', function() {
                    // This would typically trigger a form submission or AJAX request
                    console.log(`Applying price filter: $${minValue} - $${maxValue}`);

                    // Here you would typically add code to filter products or redirect to a filtered URL
                });
            }

            // Helper function to update the slider progress bar
            function updateSliderProgress() {
                const range = sliderMax - sliderMin;
                const minPercent = ((minValue - sliderMin) / range) * 100;
                const maxPercent = ((maxValue - sliderMin) / range) * 100;

                sliderProgress.style.left = `${minPercent}%`;
                sliderProgress.style.width = `${maxPercent - minPercent}%`;
            }

            // Helper function to update price displays
            function updateDisplays() {
                minPriceDisplay.textContent = `$${minValue}`;
                maxPriceDisplay.textContent = `$${maxValue}`;
            }
        });
    }
    priceRangeWidget();

    /**
     * Initiate glightbox
     */
    const glightbox = GLightbox({
        selector: '.glightbox'
    });

    /**
     * Initiate Pure Counter
     */
    new PureCounter();

    /**
     * Frequently Asked Questions Toggle
     */
    document.querySelectorAll('.faq-item h3, .faq-item .faq-toggle, .faq-item .faq-header').forEach((faqItem) => {
        faqItem.addEventListener('click', () => {
            faqItem.parentNode.classList.toggle('faq-active');
        });
    });

})();