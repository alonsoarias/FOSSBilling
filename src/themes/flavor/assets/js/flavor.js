/**
 * FOSSBilling Flavor Theme JavaScript
 * Powered by IngeWeb - https://ingeweb.co
 *
 * Standalone version that works without webpack
 * Provides all necessary FOSSBilling compatibility
 */

/**
 * bb global object - FOSSBilling compatibility layer
 */
globalThis.bb = {
    post: function(url, params, successHandler) {
        API.makeRequest(
            'POST',
            bb.restUrl(url),
            JSON.stringify(params),
            successHandler,
            function(error) {
                FOSSBilling.message(error.message, 'error');
            }
        );
    },

    get: function(url, params, successHandler) {
        API.makeRequest(
            'GET',
            bb.restUrl(url),
            params,
            successHandler,
            function(error) {
                FOSSBilling.message(error.message, 'error');
            }
        );
    },

    restUrl: function(url) {
        if (url.indexOf('http://') > -1 || url.indexOf('https://') > -1) {
            return url;
        }
        return $('meta[property="bb:url"]').attr('content') + 'index.php?_url=/api/' + url;
    },

    error: function(txt, code) {
        FOSSBilling.message(txt + ' (' + code + ')', 'error');
    },

    msg: function(txt, type) {
        FOSSBilling.message(txt, type);
    },

    redirect: function(url) {
        if (url === undefined) {
            this.reload();
        }
        window.location = url;
    },

    reload: function() {
        window.location.reload(true);
    },

    load: function(url, params) {
        var r = '';
        $.ajax({
            url: url,
            data: params,
            type: 'GET',
            success: function(data) {
                r = data;
            },
            async: false
        });
        return r;
    },

    _afterComplete: function(obj, result) {
        var jsonp = obj.getAttribute('data-api-jsonp');

        if (jsonp !== null && window.hasOwnProperty(jsonp)) {
            return window[jsonp](result);
        }

        if (obj.classList.contains('bb-rm-tr')) {
            obj.closest('tr').classList.add('highlight');
            return;
        }

        if (obj.hasAttribute('data-api-redirect')) {
            window.location = obj.getAttribute('data-api-redirect');
            return;
        }

        if (obj.hasAttribute('data-api-reload')) {
            window.location.reload();
            return;
        }

        if (obj.hasAttribute('data-api-msg')) {
            FOSSBilling.message(obj.getAttribute('data-api-msg'), 'success');
            return;
        }

        if (result) {
            FOSSBilling.message('Form updated', 'success');
            return;
        }
    },

    apiForm: function() {
        const formElements = document.getElementsByClassName('api-form');

        if (formElements.length > 0) {
            for (let i = 0; i < formElements.length; i++) {
                const formElement = formElements[i];

                formElement.addEventListener('submit', function(event) {
                    event.preventDefault();
                    const formData = new FormData(formElement);

                    // Handle CKEditor instances if present
                    if (typeof editors !== 'undefined' && Array.isArray(editors) && editors.length > 0) {
                        let editorContentOnRequiredAttr = false;
                        Object.keys(editors).forEach(function(name) {
                            editorContentOnRequiredAttr = editors[name].required
                                ? editors[name].editor.getData() !== ''
                                : true;
                            formData.set(name, editors[name].editor.getData());
                        });
                        if (!editorContentOnRequiredAttr) {
                            return FOSSBilling.message('At least one of the required fields are empty', 'error');
                        }
                    }

                    let data;
                    if (formElement.getAttribute('method').toLowerCase() !== 'get') {
                        data = formData.serializeJSON();
                    } else {
                        data = formData.serialize();
                    }

                    let buttons = document.querySelectorAll('button:not([disabled])');

                    buttons.forEach(function(button) {
                        button.setAttribute('disabled', 'true');
                    });

                    API.makeRequest(
                        formElement.getAttribute('method'),
                        bb.restUrl(formElement.getAttribute('action')),
                        data,
                        function(result) {
                            buttons.forEach(function(button) {
                                button.removeAttribute('disabled');
                            });
                            return bb._afterComplete(formElement, result);
                        },
                        function(error) {
                            buttons.forEach(function(button) {
                                button.removeAttribute('disabled');
                            });
                            FOSSBilling.message(error.message + ' (' + error.code + ')', 'error');
                        }
                    );
                });
            }
        }
    },

    apiLink: function() {
        const linkElements = document.getElementsByClassName('api-link');

        if (linkElements.length > 0) {
            for (let i = 0; i < linkElements.length; i++) {
                const linkElement = linkElements[i];

                linkElement.addEventListener('click', function(event) {
                    event.preventDefault();

                    if (linkElement.dataset.apiConfirm) {
                        if (confirm(linkElement.dataset.apiConfirm)) {
                            API.makeRequest(
                                'GET',
                                bb.restUrl(linkElement.getAttribute('href')),
                                {},
                                function(result) {
                                    return bb._afterComplete(linkElement, result);
                                },
                                function(error) {
                                    FOSSBilling.message(error.message + ' (' + error.code + ')', 'error');
                                }
                            );
                        }
                    } else {
                        API.makeRequest(
                            'GET',
                            bb.restUrl(linkElement.getAttribute('href')),
                            {},
                            function(result) {
                                return bb._afterComplete(linkElement, result);
                            },
                            function(error) {
                                FOSSBilling.message(error.message + ' (' + error.code + ')', 'error');
                            }
                        );
                    }
                    return false;
                });
            }
        }
    },

    menuAutoActive: function() {
        var matches = $('ul#menu li a').filter(function() {
            return document.location.href == this.href;
        });
        matches.parents('li').addClass('active');
    },

    cookieCreate: function(name, value, days) {
        var expires = '';
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = '; expires=' + date.toGMTString();
        }
        document.cookie = name + '=' + value + expires + '; path=/';
    },

    cookieRead: function(name) {
        var nameEQ = name + '=';
        var ca = document.cookie.split(';');
        for (var i = 0; i < ca.length; i++) {
            var c = ca[i];
            while (c.charAt(0) == ' ') c = c.substring(1, c.length);
            if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length, c.length);
        }
        return null;
    },

    insertToTextarea: function(areaId, text) {
        var txtarea = document.getElementById(areaId);
        var scrollPos = txtarea.scrollTop;
        var strPos = 0;
        var br = (txtarea.selectionStart || txtarea.selectionStart == '0') ? 'ff' : (document.selection ? 'ie' : false);

        if (br == 'ie') {
            txtarea.focus();
            var range = document.selection.createRange();
            range.moveStart('character', -txtarea.value.length);
            strPos = range.text.length;
        } else if (br == 'ff') {
            strPos = txtarea.selectionStart;
        }

        var front = txtarea.value.substring(0, strPos);
        var back = txtarea.value.substring(strPos, txtarea.value.length);
        txtarea.value = front + text + back;
        strPos = strPos + text.length;

        if (br == 'ie') {
            txtarea.focus();
            var range = document.selection.createRange();
            range.moveStart('character', -txtarea.value.length);
            range.moveStart('character', strPos);
            range.moveEnd('character', 0);
            range.select();
        } else if (br == 'ff') {
            txtarea.selectionStart = strPos;
            txtarea.selectionEnd = strPos;
            txtarea.focus();
        }
        txtarea.scrollTop = scrollPos;

        if (typeof CKEDITOR !== 'undefined') {
            CKEDITOR.instances[areaId].insertText(text);
        }
        return false;
    },

    currency: function(price, rate, title, multiply) {
        price = parseFloat(price) * parseFloat(rate);
        if (multiply !== undefined) {
            price = price * multiply;
        }
        return price.toFixed(2) + ' ' + title;
    }
};

/**
 * FOSSBilling global object - Message system
 */
globalThis.FOSSBilling = {
    message: function(message, type) {
        type = type || 'info';
        let color;
        switch (type) {
            case 'error':
                color = 'danger';
                break;
            case 'warning':
                color = 'warning';
                break;
            case 'success':
                color = 'success';
                break;
            default:
                color = 'primary';
        }

        let container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
            container.style.zIndex = '1070';
            document.body.appendChild(container);
        }

        const element = document.createElement('div');
        container.appendChild(element);
        element.classList.add('toast', 'show');
        element.setAttribute('role', 'alert');
        element.setAttribute('aria-live', 'assertive');
        element.setAttribute('aria-atomic', 'true');

        const headerDiv = document.createElement('div');
        headerDiv.className = 'toast-header';

        const spanEl = document.createElement('span');
        spanEl.className = 'p-2 border border-light bg-' + color + ' rounded-circle me-2';
        headerDiv.appendChild(spanEl);

        const strongEl = document.createElement('strong');
        strongEl.className = 'me-auto';
        strongEl.textContent = 'System message';
        headerDiv.appendChild(strongEl);

        const closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'btn-close';
        closeButton.setAttribute('data-bs-dismiss', 'toast');
        closeButton.setAttribute('aria-label', 'Close');
        headerDiv.appendChild(closeButton);

        element.appendChild(headerDiv);

        const bodyDiv = document.createElement('div');
        bodyDiv.className = 'toast-body';
        bodyDiv.textContent = message;
        element.appendChild(bodyDiv);

        element.addEventListener('hidden.bs.toast', function() {
            container.removeChild(element);
        });

        const toast = new bootstrap.Toast(element);
        toast.show();
    },

    backToTop: function() {
        const backToTopBtn = document.getElementById('backToTop');
        if (backToTopBtn) {
            window.addEventListener('scroll', function() {
                if (window.scrollY > 300) {
                    backToTopBtn.classList.add('show');
                } else {
                    backToTopBtn.classList.remove('show');
                }
            });

            backToTopBtn.addEventListener('click', function(e) {
                e.preventDefault();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }
    }
};

/**
 * Flash message handler
 */
globalThis.flashMessage = function({message = '', reload = false, type = 'info'}) {
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

/**
 * jQuery Simple Tabs plugin
 */
$.fn.simpleTabs = function() {
    $(this).find('.tab_content').hide();
    $(this).find('ul.tabs li:first').addClass('activeTab').show();
    $(this).find('.tab_content:first').show();

    $('ul.tabs li').on('click', function() {
        $(this).parent().parent().find('ul.tabs li').removeClass('activeTab');
        $(this).addClass('activeTab');
        $(this).parent().parent().find('.tab_content').hide();
        var activeTab = $(this).find('a').attr('href');
        $(activeTab).show();
        return false;
    });

    if ($(document.location.hash).length) {
        $('a[href="' + document.location.hash + '"]').parent().trigger('click');
        $(window).scrollTop(window.location.href.indexOf('#'));
    }
};

/**
 * DOMContentLoaded initialization
 */
document.addEventListener('DOMContentLoaded', function() {
    // Initialize flash messages
    flashMessage({});

    // Initialize API forms and links
    if (document.querySelector('form.api-form')) {
        bb.apiForm();
    }
    if (document.querySelector('a.api-link')) {
        bb.apiLink();
    }

    // Enable Bootstrap Tooltips
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));

    // Add asterisk to required field labels
    const requiredInputs = document.querySelectorAll('input[required], textarea[required], select[required]');
    requiredInputs.forEach(input => {
        const label = input.previousElementSibling;
        const isAuth = input.closest('.auth') || input.closest('.auth-card');
        if (!isAuth && label && label.tagName.toLowerCase() === 'label' && !label.querySelector('.text-danger')) {
            const asterisk = document.createElement('span');
            asterisk.textContent = ' *';
            asterisk.classList.add('text-danger');
            label.appendChild(asterisk);
        }
    });

    // Currency selector
    const currencySelector = document.querySelectorAll('select.currency_selector');
    currencySelector.forEach(function(select) {
        select.addEventListener('change', function() {
            if (typeof API !== 'undefined') {
                API.guest.post('cart/set_currency', { currency: select.value }, function(response) {
                    location.reload();
                }, function(error) {
                    FOSSBilling.message(error, 'error');
                });
            }
        });
    });

    // Language selector
    const jsLanguageSelector = document.querySelector('.js-language-selector');
    if (jsLanguageSelector) {
        if (typeof TomSelect !== 'undefined') {
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
        } else {
            jsLanguageSelector.addEventListener('change', function() {
                document.cookie = 'BBLANG=' + this.value + ';path=/';
                window.location.reload();
            });
        }
    }

    // Initialize simple tabs
    if ($('div.simpleTabs').length) {
        $('div.simpleTabs').simpleTabs();
    }

    // Ajax loading indicator
    $(document).ajaxStart(function() {
        $('.wait, .loading').show();
    }).ajaxStop(function() {
        $('.wait, .loading').hide();
    });

    // Close message boxes
    $(document).on('click', 'div.msg span.close', function() {
        $(this).parent().slideUp(70);
        return false;
    });

    // Hideable elements
    $('.hideit').on('click', function() {
        $(this).fadeOut(400);
    });

    // Initialize back to top
    FOSSBilling.backToTop();

    // Smooth scroll for anchor links
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
});
