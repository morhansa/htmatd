/**
 * MagoArab_WithoutEmail extension
 *
 * @category  MagoArab
 * @package   MagoArab_WithoutEmail
 * @author    MagoArab
 */
define([
    'jquery',
    'mage/translate',
    'intlTelInput'
], function ($, $t) {
    'use strict';
    
    return function (config) {
        $(document).ready(function () {
            var minLength = config.minLength || 9;
            var maxLength = config.maxLength || 15;
            var allowedCountries = config.allowedCountries || [];
            var preferredCountries = config.preferredCountries || ['EG', 'US', 'GB', 'SA', 'AE'];
            var defaultCountry = config.defaultCountry || 'EG';
            var utilsPath = config.utilsPath || '';
            
            // Initialize international telephone input on phone-input fields
            $('.phone-input').each(function() {
                var input = this;
                
                try {
                    // Initialize the intlTelInput plugin with improved configuration
                    var iti = window.intlTelInput(input, {
                        initialCountry: defaultCountry,
                        preferredCountries: preferredCountries,
                        onlyCountries: allowedCountries,
                        separateDialCode: true,
                        autoHideDialCode: false,
                        nationalMode: true, // Permitir formato local
                        formatOnDisplay: false, // No formatear automáticamente
                        utilsScript: utilsPath
                    });
                    
                    // Store the instance for later use
                    $(input).data('iti', iti);
                    
                    // Add validation for the form with improved handling
                    $(input).closest('form').on('submit', function(e) {
                        try {
                            var phoneNumber = input.value;
                            var formattedNumber = '';
                            
                            // Intentar obtener el número formateado
                            formattedNumber = iti.getNumber();
                            
                            // Si no se pudo formatear o es inválido, intentar formatear manualmente
                            if (!formattedNumber || !iti.isValidNumber()) {
                                var countryData = iti.getSelectedCountryData();
                                var dialCode = countryData.dialCode || '';
                                
                                // Número egipcio típico: 01xxxxxxxxx
                                if (countryData.iso2 === 'eg' && phoneNumber.match(/^0[1][0-9]{9}$/)) {
                                    formattedNumber = '+2' + phoneNumber;
                                }
                                // Números que empiezan con 0 (formato local)
                                else if (phoneNumber.startsWith('0')) {
                                    formattedNumber = '+' + dialCode + phoneNumber.substring(1);
                                }
                                // Números sin formato internacional
                                else if (!phoneNumber.startsWith('+')) {
                                    formattedNumber = '+' + dialCode + phoneNumber;
                                }
                                // Si ya tiene prefijo internacional
                                else {
                                    formattedNumber = phoneNumber;
                                }
                            }
                            
                            // Guardar el número formateado
                            input.value = formattedNumber;
                            
                            // Eliminar errores previos
                            $('.phone-error-message').remove();
                            
                            // Verificar longitud básica antes de enviar
                            var digitsOnly = formattedNumber.replace(/\D/g, '');
                            if (digitsOnly.length < 7) {
                                $(input).after('<div class="phone-error-message">' + $t('The phone number seems too short') + '</div>');
                                e.preventDefault();
                                return false;
                            }
                            
                            return true;
                        } catch (validationError) {
                            console.error('Phone validation error:', validationError);
                            // Allow form submission in case of validation error
                            return true;
                        }
                    });
                    
                    // Format on blur
                    $(input).on('blur', function() {
                        try {
                            var phoneNumber = input.value;
                            if (!phoneNumber) {
                                return;
                            }
                            
                            var formattedNumber = '';
                            
                            // Intentar obtener el número formateado
                            formattedNumber = iti.getNumber();
                            
                            // Si no se pudo formatear o es inválido, intentar formatear manualmente
                            if (!formattedNumber || !iti.isValidNumber()) {
                                var countryData = iti.getSelectedCountryData();
                                var dialCode = countryData.dialCode || '';
                                
                                // Número egipcio típico: 01xxxxxxxxx
                                if (countryData.iso2 === 'eg' && phoneNumber.match(/^0[1][0-9]{9}$/)) {
                                    formattedNumber = '+2' + phoneNumber;
                                }
                                // Números que empiezan con 0 (formato local)
                                else if (phoneNumber.startsWith('0')) {
                                    formattedNumber = '+' + dialCode + phoneNumber.substring(1);
                                }
                                // Números sin formato internacional
                                else if (!phoneNumber.startsWith('+')) {
                                    formattedNumber = '+' + dialCode + phoneNumber;
                                }
                                // Si ya tiene prefijo internacional
                                else {
                                    formattedNumber = phoneNumber;
                                }
                            }
                            
                            // Actualizar el valor
                            input.value = formattedNumber;
                        } catch (error) {
                            console.error('Error formatting phone number:', error);
                        }
                    });
                } catch (error) {
                    console.error('Error initializing intlTelInput:', error);
                }
            });
            
            // Highlight phone field
            $('.phone-field-highlight input').focus(function () {
                $(this).closest('.field').addClass('focused');
            }).blur(function () {
                $(this).closest('.field').removeClass('focused');
            });
        });
    };
});