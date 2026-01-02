/**
 * Flavor Theme - Main JavaScript
 * Modern client area theme for FOSSBilling
 */

// Global bb object for compatibility
window.bb = {
    restUrl: function(url) {
        if (url.indexOf('http://') > -1 || url.indexOf('https://') > -1) {
            return url;
        }
        return document.querySelector('meta[property="bb:url"]').getAttribute('content') + 'index.php?_url=/api/' + url;
    },

    redirect: function(url) {
        if (url === undefined) {
            this.reload();
            return;
        }
        window.location = url;
    },

    reload: function() {
        window.location.reload(true);
    },

    // Deprecated methods for backwards compatibility
    post: function(url, params, successHandler) {
        API.makeRequest('POST', bb.restUrl(url), JSON.stringify(params), successHandler, function(error) {
            FOSSBilling.message(error.message, 'error');
        });
    },

    get: function(url, params, successHandler) {
        API.makeRequest('GET', bb.restUrl(url), params, successHandler, function(error) {
            FOSSBilling.message(error.message, 'error');
        });
    }
};

// FOSSBilling global object
window.FOSSBilling = {
    /**
     * Display a toast notification
     * @param {string} message - The message to display
     * @param {string} type - The type of message (success, error, warning, info)
     */
    message: function(message, type = 'info') {
        const toastContainer = document.querySelector('.toast-container');
        if (!toastContainer) return;

        const iconMap = {
            success: 'fa-circle-check',
            error: 'fa-circle-exclamation',
            warning: 'fa-triangle-exclamation',
            info: 'fa-circle-info'
        };

        const bgMap = {
            success: 'bg-success',
            error: 'bg-danger',
            warning: 'bg-warning',
            info: 'bg-primary'
        };

        const toastId = 'toast-' + Date.now();
        const toastHtml = `
            <div id="${toastId}" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="toast-header">
                    <span class="rounded me-2 ${bgMap[type] || bgMap.info}" style="width: 20px; height: 20px; display: inline-flex; align-items: center; justify-content: center;">
                        <i class="fa-solid ${iconMap[type] || iconMap.info} text-white" style="font-size: 12px;"></i>
                    </span>
                    <strong class="me-auto">${type.charAt(0).toUpperCase() + type.slice(1)}</strong>
                    <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
                <div class="toast-body">
                    ${message}
                </div>
            </div>
        `;

        toastContainer.insertAdjacentHTML('beforeend', toastHtml);

        const toastElement = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastElement, {
            autohide: true,
            delay: 5000
        });
        toast.show();

        toastElement.addEventListener('hidden.bs.toast', function() {
            toastElement.remove();
        });
    }
};

// Document Ready
document.addEventListener('DOMContentLoaded', function() {
    /**
     * Initialize Bootstrap Tooltips
     */
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));

    /**
     * Flash Message Handler
     */
    window.flashMessage = function({message = '', reload = false, type = 'info'}) {
        const key = 'flash-message';
        const sessionMessage = sessionStorage.getItem(key);

        if (message === '' && sessionMessage) {
            FOSSBilling.message(sessionMessage, type);
            sessionStorage.removeItem(key);
            return;
        }

        if (message) {
            sessionStorage.setItem(key, message);
            if (typeof reload === 'boolean' && reload) {
                bb.reload();
            } else if (typeof reload === 'string') {
                bb.redirect(reload);
            }
        }
    };
    flashMessage({});

    /**
     * Language Selector Handler
     */
    function handleLanguageChange(locale) {
        // Set cookie
        document.cookie = `BBLANG=${locale};path=/;max-age=31536000`;
        // Reload page
        window.location.reload();
    }

    // Dropdown language items
    document.querySelectorAll('.lang-item').forEach(function(item) {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            handleLanguageChange(this.dataset.lang);
        });
    });

    // Select language selector (mobile)
    document.querySelectorAll('.lang-selector').forEach(function(select) {
        select.addEventListener('change', function() {
            handleLanguageChange(this.value);
        });

        // Set current language
        const currentLang = document.cookie.split('; ').find(row => row.startsWith('BBLANG='));
        if (currentLang) {
            select.value = currentLang.split('=')[1];
        }
    });

    /**
     * Add asterisk to required field labels
     */
    const requiredInputs = document.querySelectorAll('input[required], textarea[required], select[required]');
    requiredInputs.forEach(function(input) {
        const label = input.closest('.mb-3, .form-group')?.querySelector('label');
        if (label && !label.querySelector('.text-danger')) {
            const asterisk = document.createElement('span');
            asterisk.textContent = ' *';
            asterisk.classList.add('text-danger');
            label.appendChild(asterisk);
        }
    });

    /**
     * API Form Handler
     */
    document.querySelectorAll('.api-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(form);
            const action = form.getAttribute('action');
            const redirectUrl = form.dataset.apiRedirect;
            const successMessage = form.dataset.apiMsg || 'Operation completed successfully';

            // Add CSRF token
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            if (csrfToken) {
                formData.append('CSRFToken', csrfToken);
            }

            // Convert FormData to object
            const data = {};
            formData.forEach((value, key) => {
                if (key.endsWith('[]')) {
                    const arrayKey = key.slice(0, -2);
                    if (!data[arrayKey]) data[arrayKey] = [];
                    data[arrayKey].push(value);
                } else {
                    data[key] = value;
                }
            });

            // Make API request
            fetch(action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(response => {
                if (response.error) {
                    FOSSBilling.message(response.error.message, 'error');
                    return;
                }

                if (redirectUrl) {
                    if (successMessage) {
                        sessionStorage.setItem('flash-message', successMessage);
                    }
                    window.location.href = redirectUrl;
                } else {
                    FOSSBilling.message(successMessage, 'success');
                }
            })
            .catch(error => {
                FOSSBilling.message('An error occurred. Please try again.', 'error');
                console.error('API Form Error:', error);
            });
        });
    });

    /**
     * API Link Handler
     */
    document.querySelectorAll('.api-link').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();

            const url = this.getAttribute('href');
            const confirmMsg = this.dataset.apiConfirm;
            const successMessage = this.dataset.apiMsg || 'Operation completed successfully';
            const reloadOnSuccess = this.dataset.apiReload !== 'false';

            if (confirmMsg && !confirm(confirmMsg)) {
                return;
            }

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ CSRFToken: csrfToken })
            })
            .then(response => response.json())
            .then(response => {
                if (response.error) {
                    FOSSBilling.message(response.error.message, 'error');
                    return;
                }

                FOSSBilling.message(successMessage, 'success');

                if (reloadOnSuccess) {
                    setTimeout(() => window.location.reload(), 1000);
                }
            })
            .catch(error => {
                FOSSBilling.message('An error occurred. Please try again.', 'error');
                console.error('API Link Error:', error);
            });
        });
    });

    /**
     * Currency Selector Handler
     */
    document.querySelectorAll('.currency_selector').forEach(function(select) {
        select.addEventListener('change', function() {
            API.guest.post('cart/set_currency', { currency: this.value }, function(response) {
                location.reload();
            }, function(error) {
                FOSSBilling.message(error.message || 'Failed to change currency', 'error');
            });
        });
    });

    /**
     * Auto-hide alerts after 5 seconds
     */
    document.querySelectorAll('.alert:not(.alert-permanent)').forEach(function(alert) {
        setTimeout(function() {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
});
