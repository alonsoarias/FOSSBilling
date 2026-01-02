/**
 * FOSSBilling Flavor Theme JavaScript
 * Powered by IngeWeb
 */

document.addEventListener('DOMContentLoaded', function() {
    /**
     * Back to Top Button
     */
    const btnToTop = document.getElementById('btnToTop');
    if (btnToTop) {
        window.addEventListener('scroll', function() {
            if (window.scrollY > 300) {
                btnToTop.classList.add('show');
            } else {
                btnToTop.classList.remove('show');
            }
        });
    }

    /**
     * Enable Bootstrap Tooltips
     */
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));

    /**
     * Language Selector
     */
    const langItems = document.querySelectorAll('.lang-item');
    langItems.forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            const locale = this.dataset.lang;
            if (locale) {
                document.cookie = 'BBLANG=' + locale + ';path=/';
                window.location.reload();
            }
        });
    });

    /**
     * Manage flash message to show after page reload
     */
    globalThis.flashMessage = function({message = '', reload = false, type = 'info'}) {
        const key = 'flash-message';
        const sessionMessage = sessionStorage.getItem(key);

        if (message === '' && sessionMessage) {
            if (typeof FOSSBilling !== 'undefined' && FOSSBilling.message) {
                FOSSBilling.message(sessionMessage, type);
            }
            sessionStorage.removeItem(key);
            return;
        }

        if (message) {
            sessionStorage.setItem(key, message);
            if (typeof reload === 'boolean' && reload) {
                window.location.reload();
            } else if (typeof reload === 'string') {
                window.location.href = reload;
            }
        }
    };
    flashMessage({});

    /**
     * Add asterisk to required field labels
     */
    const requiredInputs = document.querySelectorAll('input[required], textarea[required], select[required]');
    requiredInputs.forEach(input => {
        const label = input.previousElementSibling;
        const isAuth = input.closest('.auth-card');
        if (!isAuth && label && label.tagName.toLowerCase() === 'label' && !label.querySelector('.text-danger')) {
            const asterisk = document.createElement('span');
            asterisk.textContent = ' *';
            asterisk.classList.add('text-danger');
            label.appendChild(asterisk);
        }
    });

    /**
     * Currency Selector
     */
    const currencySelector = document.querySelectorAll('select.currency_selector');
    currencySelector.forEach(function(select) {
        select.addEventListener('change', function() {
            if (typeof API !== 'undefined') {
                API.guest.post('cart/set_currency', { currency: select.value }, function(response) {
                    location.reload();
                }, function(error) {
                    if (typeof FOSSBilling !== 'undefined' && FOSSBilling.message) {
                        FOSSBilling.message(error);
                    }
                });
            }
        });
    });

    /**
     * Period Selector for Pricing
     */
    const periodSelector = document.getElementById('period-selector');
    if (periodSelector) {
        function updatePeriodVisibility() {
            const selectedPeriod = periodSelector.value;
            document.querySelectorAll('.period').forEach(el => {
                el.style.display = 'none';
            });
            document.querySelectorAll('.period.' + selectedPeriod).forEach(el => {
                el.style.display = '';
            });
        }

        periodSelector.addEventListener('change', updatePeriodVisibility);
        updatePeriodVisibility();
    }

    /**
     * Auto-hide alerts after delay
     */
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            if (bsAlert) {
                bsAlert.close();
            }
        }, 8000);
    });

    /**
     * Smooth scroll for anchor links
     */
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const targetId = this.getAttribute('href');
            if (targetId === '#' || targetId === '#top') {
                e.preventDefault();
                window.scrollTo({ top: 0, behavior: 'smooth' });
                return;
            }

            const target = document.querySelector(targetId);
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    /**
     * Form validation styling
     */
    const forms = document.querySelectorAll('.needs-validation');
    forms.forEach(form => {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    });

    /**
     * TomSelect initialization for language selector (if available)
     */
    const jsLanguageSelector = document.querySelector('.js-language-selector');
    if (jsLanguageSelector && typeof TomSelect !== 'undefined') {
        new TomSelect(jsLanguageSelector, {
            render: {
                option: function(data, escape) {
                    const customProps = data.$option ? data.$option.dataset.customProperties : '';
                    return '<div>' + customProps + ' ' + escape(data.text) + '</div>';
                },
                item: function(data, escape) {
                    const customProps = data.$option ? data.$option.dataset.customProperties : '';
                    return '<div>' + customProps + ' ' + escape(data.text) + '</div>';
                }
            },
            onChange: function(value) {
                if (value) {
                    document.cookie = 'BBLANG=' + value + ';path=/';
                    window.location.reload();
                }
            }
        });
    }
});

/**
 * Global helper for displaying toast messages
 */
globalThis.showToast = function(message, type = 'info') {
    const toastContainer = document.querySelector('.toast-container');
    if (!toastContainer) return;

    const icons = {
        success: 'fa-circle-check',
        error: 'fa-circle-exclamation',
        warning: 'fa-triangle-exclamation',
        info: 'fa-circle-info'
    };

    const bgColors = {
        success: 'bg-success',
        error: 'bg-danger',
        warning: 'bg-warning',
        info: 'bg-primary'
    };

    const toastEl = document.createElement('div');
    toastEl.className = 'toast align-items-center text-white ' + (bgColors[type] || bgColors.info);
    toastEl.setAttribute('role', 'alert');
    toastEl.setAttribute('aria-live', 'assertive');
    toastEl.setAttribute('aria-atomic', 'true');

    toastEl.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">
                <i class="fa-solid ${icons[type] || icons.info} me-2"></i>
                ${message}
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    `;

    toastContainer.appendChild(toastEl);
    const toast = new bootstrap.Toast(toastEl, { delay: 5000 });
    toast.show();

    toastEl.addEventListener('hidden.bs.toast', function() {
        toastEl.remove();
    });
};
