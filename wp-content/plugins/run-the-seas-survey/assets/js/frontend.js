jQuery(document).ready(function ($) {
  // ============================================
  // GLOBAL VARIABLES
  // ============================================

  var rtsTracking = {
    tracking_id: 0,
    tracking_token: "",
    form_id: 0,
    current_step: 1,
    total_steps: 0,
    answered_questions: {},
    initialized: false,
    is_submitting: false,
    is_completed: false,
    email: "",
    email_tracked: false,
    tracking_started: false,
    session_id: "",
    form_started: false,
    completion_attempted: false,
    referral_code: "",
    referral_source: "",
    status_checked: false,
    is_active: false,
    is_review_mode: false,
    tracking_start_requested: false,
    cookie_consent: null,
    consent_prompt_open: false,
    consent_callbacks: [],
  };

  var rtsReviewData = {
    answers: {},
    isReviewStep: false,
    originalAnswers: {},
    pendingChanges: {},
    isReviewMode: false,
    fieldStepMap: {},
    reviewSessionActive: false,
  };

  var textareaDebounceTimer = null;
  var textareaLastValue = {};
  var inputDebounceTimers = {};
  var inputLastValue = {};
  var emailDebounceTimer = null;
  var abandonmentSent = false;

  // ============================================
  // UTILITY FUNCTIONS
  // ============================================

  function getQuestionLabel($field) {
    var $group = $field.closest(".ff-el-group");

    var $label = $group.find(".ff-el-input--label label");
    if ($label.length) {
      return $label.text().trim();
    }

    var $fieldset = $field.closest("fieldset");
    if ($fieldset.length) {
      var $legend = $fieldset.find("legend");
      if ($legend.length) {
        return $legend.text().trim();
      }
    }

    var ariaLabel = $field.attr("aria-label");
    if (ariaLabel) {
      return ariaLabel;
    }

    var placeholder = $field.attr("placeholder");
    if (placeholder) {
      return placeholder;
    }

    var dataLabel = $field.data("label");
    if (dataLabel) {
      return dataLabel;
    }

    var $parentLabel = $field.closest("label");
    if ($parentLabel.length) {
      var labelText = $parentLabel.text().trim();
      if (labelText) {
        return labelText;
      }
    }

    return $field.attr("name") || "Question";
  }

  function getStepNumber(stepName, formId) {
    if (!stepName) return 1;

    var match = stepName.match(/form_step-(\d+)_(\d+)/);
    if (match) {
      return parseInt(match[2]) + 1;
    }

    match = stepName.match(/step_start-(\d+)_(\d+)/);
    if (match) {
      return 1;
    }

    match = stepName.match(/step_(\d+)/);
    if (match) {
      return parseInt(match[1]);
    }

    return 1;
  }

  function getStepElements($form) {
    if (!$form || !$form.length) {
      $form = $(".fluentform form");
    }

    var $steps = $form.find(".ff-step-body .fluentform-step");
    if (!$steps.length) {
      $steps = $form.find(".fluentform-step");
    }

    return $steps;
  }

  function getStepIndex($step, $form) {
    var $steps = getStepElements($form);
    if (!$step || !$step.length || !$steps.length) {
      return 1;
    }

    var stepIndex = $steps.index($step);
    return stepIndex >= 0 ? stepIndex + 1 : 1;
  }

  function getCurrentStep($form) {
    if (!$form || !$form.length) {
      $form = $(".fluentform form");
    }
    if (!$form || !$form.length) {
      return 1;
    }

    var $activeStep = $form.find(".ff-step-body .fluentform-step.active");
    if ($activeStep.length) {
      return getStepIndex($activeStep.first(), $form);
    }

    var $activeTitle = $form.find(
      ".ff-step-titles [data-step-number].ff_active, " +
      ".ff-step-titles [data-step-number].rts-question-nav-is-active",
    ).first();
    if ($activeTitle.length) {
      return (parseInt($activeTitle.attr("data-step-number"), 10) || 0) + 1;
    }

    return 1;
  }

  function getFieldStepNumber($field, $form) {
    if (!$field || !$field.length) {
      return getCurrentStep($form);
    }

    var $step = $field.closest(".fluentform-step");
    if ($step.length) {
      return getStepIndex($step.first(), $form);
    }

    return getCurrentStep($form);
  }

  function getStepContainer($form, stepNumber) {
    var $steps = getStepElements($form);
    var stepIndex = parseInt(stepNumber, 10) - 1;
    if (stepIndex < 0 || stepIndex >= $steps.length) {
      return $();
    }

    return $steps.eq(stepIndex);
  }

  function renderReturnToReviewButton() {
    if (!rtsReviewData.reviewSessionActive || rtsTracking.is_review_mode) {
      return;
    }

    var returnBarHtml =
      '<div id="rts-review-return-bar" style="position: fixed; right: 20px; bottom: 20px; z-index: 99998; display: flex; gap: 10px; align-items: center; background: rgba(255,255,255,0.96); padding: 10px 12px; border-radius: 999px; box-shadow: 0 8px 24px rgba(0,0,0,0.15);">';
    returnBarHtml +=
      '<span style="font-size: 13px; color: #555;">Need to edit again?</span>';
    returnBarHtml +=
      '<button type="button" onclick="rtsReturnToReview()" style="padding: 10px 16px; background: #1a7efb; color: #fff; border: none; border-radius: 999px; cursor: pointer; font-size: 13px; font-weight: 600;">↩ Back to Review</button>';
    returnBarHtml += "</div>";

    $("body").append(returnBarHtml);
  }

  function renderStepJumpOverlay(stepNumber, fieldName) {
    var label = fieldName || "question";
    var stepLabel = stepNumber ? "Step " + stepNumber : "the selected step";
    var overlayHtml =
      '<div id="rts-step-jump-overlay" style="position: fixed; inset: 0; z-index: 99999; background: rgba(255,255,255,0.72); backdrop-filter: blur(3px); display: flex; align-items: center; justify-content: center; padding: 20px;">';
    overlayHtml +=
      '<div style="background: #fff; border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.18); padding: 22px 26px; max-width: 360px; width: 100%; text-align: center; border: 1px solid rgba(26,126,251,0.14);">';
    overlayHtml +=
      '<div class="spinner is-active" style="float: none; margin: 0 auto 14px auto;"></div>';
    overlayHtml +=
      '<div style="font-size: 17px; font-weight: 700; color: #1a7efb; margin-bottom: 6px;">Opening edit step</div>';
    overlayHtml +=
      '<div style="font-size: 14px; color: #555; line-height: 1.5;">Jumping back to ' +
      stepLabel +
      " for <strong>" +
      label +
      '</strong>.</div>';
    overlayHtml += "</div></div>";

    $("body").append(overlayHtml);
  }

  function removeStepJumpOverlay() {
    $("#rts-step-jump-overlay").remove();
  }

  function buildRegistrationUrl(trackingId, formId, trackingToken) {
    var queryParams = {
      tracking_id: trackingId,
      tracking_token: trackingToken || rtsTracking.tracking_token,
      form_id: formId,
      from_survey: 1,
    };

    var currentQuery = new URLSearchParams(window.location.search);
    var referralCode =
      currentQuery.get("ref") || currentQuery.get("referral_code");
    if (referralCode) {
      queryParams.ref = referralCode;
    }

    var registrationUrl =
      (window.rts_ajax && rts_ajax.registration_url) || "/register/";
    return registrationUrl + (registrationUrl.indexOf("?") === -1 ? "?" : "&") + $.param(queryParams);
  }

  function renderSurveyRewardClaimScreen(registrationUrl) {
    var claimHtml =
      '<section id="rts-survey-reward-claim" style="max-width: 820px; margin: 30px auto; padding: 32px; background: #fff; border: 2px solid #1a7efb; border-radius: 16px; box-shadow: 0 8px 28px rgba(26,126,251,0.12); color: #333;">';
    claimHtml += '<div style="text-align: center;">';
    claimHtml += '<div style="font-size: 48px; margin-bottom: 10px;">🎉</div>';
    claimHtml += '<h1 style="color: #1a7efb; font-size: 30px; line-height: 1.25; margin: 0 0 22px;">Congratulations—you’ve earned your $100 Run The Seas Cruise Credit!</h1>';
    claimHtml += '</div>';
    claimHtml += '<p style="font-size: 17px; line-height: 1.6;">Thank you for helping us create the Run The Seas experience.</p>';
    claimHtml += '<p style="font-size: 17px; line-height: 1.6; margin-bottom: 8px;">By completing the survey, you have earned:</p>';
    claimHtml += '<ul style="font-size: 16px; line-height: 1.7; padding-left: 24px;">';
    claimHtml += '<li>A $100 Run The Seas Cruise Credit</li>';
    claimHtml += '<li>Free access to your own Captain’s Suite</li>';
    claimHtml += '<li>Entry into the 42.2 k Referral Marathon Challenge</li>';
    claimHtml += '<li>The opportunity to earn digital trophies and additional rewards</li>';
    claimHtml += '</ul>';
    claimHtml += '<h2 style="color: #1a7efb; font-size: 23px; margin: 28px 0 12px;">What Happens Next?</h2>';
    claimHtml += '<p style="font-size: 16px; line-height: 1.6;">Complete the short form below so we can register your $100 Cruise Credit and create your Captain’s Suite.</p>';
    claimHtml += '<p style="font-size: 16px; line-height: 1.6; margin-bottom: 8px;">Once you submit the form:</p>';
    claimHtml += '<ul style="font-size: 16px; line-height: 1.7; padding-left: 24px;">';
    claimHtml += '<li>We’ll send you an email to verify your address.</li>';
    claimHtml += '<li>Click the verification button in the email.</li>';
    claimHtml += '<li>Your $100 Cruise Credit will be sent to you, and your Captain’s Suite will be unlocked.</li>';
    claimHtml += '</ul>';
    claimHtml += '<p style="font-size: 16px; line-height: 1.6;">It only takes a moment—complete the form below to claim your rewards.</p>';
    claimHtml += '<div style="text-align: center; margin-top: 28px;">';
    claimHtml += '<a href="' + registrationUrl + '" style="display: inline-block; padding: 15px 30px; background: #1a7efb; color: #fff; text-decoration: none; border-radius: 8px; font-size: 16px; font-weight: 700;">Claim My $100 Cruise Credit</a>';
    claimHtml += '</div></section>';

    $('.fluentform_wrapper').hide();
    $('.fluentform').hide();
    $('#rts-survey-reward-claim').remove();

    var $formWrapper = $('.fluentform_wrapper').first();
    if ($formWrapper.length) {
      $formWrapper.before(claimHtml);
    } else {
      $('body').append(claimHtml);
    }

    $('html, body').animate({ scrollTop: $('#rts-survey-reward-claim').offset().top - 30 }, 300);
  }

  function generateSessionId(shouldPersist) {
    var sessionId = shouldPersist
      ? sessionStorage.getItem("rts_session_id")
      : "";
    if (!sessionId) {
      sessionId =
        "session_" + Date.now() + "_" + Math.random().toString(36).substr(2, 9);
      if (shouldPersist) {
        sessionStorage.setItem("rts_session_id", sessionId);
      }
    }
    return sessionId;
  }

  function getCookie(name) {
    var prefix = name + "=";
    return document.cookie.split(";").reduce(function (value, cookie) {
      cookie = cookie.trim();
      return value || (cookie.indexOf(prefix) === 0 ? decodeURIComponent(cookie.slice(prefix.length)) : "");
    }, "");
  }

  function setSurveyCookieConsent() {
    document.cookie =
      "rts_survey_cookie_consent=accepted; path=/; max-age=" +
      60 * 60 * 24 * 30 +
      "; SameSite=Lax";
  }

  function resolveSurveyCookieConsent(accepted) {
    rtsTracking.cookie_consent = accepted;
    rtsTracking.consent_prompt_open = false;
    $("#rts-survey-cookie-consent").remove();

    var callbacks = rtsTracking.consent_callbacks.slice();
    rtsTracking.consent_callbacks = [];
    callbacks.forEach(function (callback) {
      callback(accepted);
    });
  }

  function requestSurveyCookieConsent(callback) {
    if (rtsTracking.cookie_consent !== null) {
      callback(rtsTracking.cookie_consent);
      return;
    }

    if (getCookie("rts_survey_cookie_consent") === "accepted") {
      rtsTracking.cookie_consent = true;
      callback(true);
      return;
    }

    rtsTracking.consent_callbacks.push(callback);
    if (rtsTracking.consent_prompt_open) {
      return;
    }

    rtsTracking.consent_prompt_open = true;
    var consentHtml =
      '<div id="rts-survey-cookie-consent" role="dialog" aria-modal="true" aria-labelledby="rts-cookie-consent-title" style="position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(0,0,0,.55);">' +
      '<div style="max-width:480px;padding:28px;background:#fff;border-radius:12px;box-shadow:0 18px 50px rgba(0,0,0,.3);color:#1d2939;">' +
      '<h2 id="rts-cookie-consent-title" style="margin:0 0 12px;font-size:22px;color:#1d2939;">Save survey information?</h2>' +
      '<p style="margin:0 0 22px;line-height:1.6;color:#475467;">Allow a cookie to save your survey information so we can recognize your completed survey if you return using this browser.</p>' +
      '<div style="display:flex;gap:12px;flex-wrap:wrap;">' +
      '<button type="button" class="rts-cookie-accept" style="padding:11px 18px;border:0;border-radius:6px;background:#1a7efb;color:#fff;font-weight:600;cursor:pointer;">Allow &amp; Continue</button>' +
      '<button type="button" class="rts-cookie-decline" style="padding:11px 18px;border:1px solid #98a2b3;border-radius:6px;background:#fff;color:#344054;font-weight:600;cursor:pointer;">Continue without saving</button>' +
      '</div></div></div>';

    $("body").append(consentHtml);
    $("#rts-survey-cookie-consent .rts-cookie-accept").on("click", function () {
      setSurveyCookieConsent();
      resolveSurveyCookieConsent(true);
    });
    $("#rts-survey-cookie-consent .rts-cookie-decline").on("click", function () {
      resolveSurveyCookieConsent(false);
    });
  }

  function getQrPageData() {
    if (window.rtsQrPageData) {
      return window.rtsQrPageData;
    }

    var $dataScript = $("#rts-qr-data");
    if ($dataScript.length) {
      try {
        window.rtsQrPageData = JSON.parse($dataScript.text() || "{}");
      } catch (error) {
        console.error("RTS QR: Failed to parse page data", error);
        window.rtsQrPageData = {};
      }
      return window.rtsQrPageData;
    }

    var $wrapper = $(".rts-registration-wrapper");
    window.rtsQrPageData = {
      participant_id: parseInt($wrapper.data("participant-id"), 10) || 0,
      card_url: $wrapper.data("card-url") || "",
      referral_link: $wrapper.data("referral-link") || "",
      referral_code: $wrapper.data("referral-code") || "",
      needs_terms: String($wrapper.data("needs-terms")) === "true",
    };
    return window.rtsQrPageData;
  }

  function updateQrCardPreview(cardUrl) {
    if (!cardUrl) return;

    window.rtsQrPageData = window.rtsQrPageData || {};
    window.rtsQrPageData.card_url = cardUrl;

    var $container = $("#rts-card-container");
    if ($container.length) {
      $container.html(
        '<img src="' +
          cardUrl +
          "?v=" +
          Date.now() +
          '" alt="QR Card" style="max-width: 100%; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">'
      );
    }
  }

  function getQrActionButton(selector, fallbackText) {
    var $button = $(selector).first();
    return {
      element: $button,
      originalText: $button.length ? $button.html() : fallbackText,
    };
  }

  function rtsCopyLink(url) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(function () {
        alert("Referral link copied to clipboard!");
      }).catch(function () {
        fallbackCopy(url);
      });
    } else {
      fallbackCopy(url);
    }
  }

  function fallbackCopy(text) {
    var input = document.createElement("input");
    input.value = text;
    document.body.appendChild(input);
    input.select();
    document.execCommand("copy");
    document.body.removeChild(input);
    alert("Referral link copied to clipboard!");
  }

  function rtsTrackShareEvent(action, platform) {
    if (typeof rts_ajax === "undefined" || !rts_ajax.ajax_url) return;

    var pageData = getQrPageData();
    jQuery.ajax({
      type: "POST",
      url: rts_ajax.ajax_url,
      data: {
        action: "rts_track_share",
        share_action: action,
        platform: platform,
        referral_code: pageData.referral_code || "",
        nonce: rts_ajax.nonce,
      },
      dataType: "json",
    });
  }

  function createTermsModal(termsHtml, callback) {
    var existingModal = document.getElementById("rts-terms-modal");
    if (existingModal) {
      existingModal.remove();
    }

    var modal = document.createElement("div");
    modal.id = "rts-terms-modal";
    modal.style.cssText =
      "position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 999999; display: flex; justify-content: center; align-items: center;";

    var content = document.createElement("div");
    content.style.cssText =
      "background: #fff; padding: 30px; border-radius: 12px; max-width: 550px; width: 95%; max-height: 85vh; overflow-y: auto;";

    content.innerHTML =
      '<h3 style="margin-top: 0; color: #1a7efb; text-align: center;">⚠️ Program Rules</h3>' +
      '<div style="font-size: 13px; line-height: 1.6; max-height: 350px; overflow-y: auto; padding: 10px; background: #f8f9fa; border-radius: 6px; margin: 10px 0;">' +
      termsHtml +
      "</div>" +
      '<div style="margin: 15px 0;">' +
      '<label style="display: flex; align-items: flex-start; gap: 10px; font-size: 14px; cursor: pointer;">' +
      '<input type="checkbox" id="rts-terms-modal-checkbox" style="margin-top: 2px; width: 18px; height: 18px;">' +
      "I agree to the program rules and terms above" +
      "</label>" +
      "</div>" +
      '<div style="display: flex; gap: 10px; justify-content: flex-end;">' +
      '<button id="rts-terms-modal-cancel" type="button" style="padding: 8px 25px; background: #6c757d; color: #fff; border: none; border-radius: 4px; cursor: pointer;">Cancel</button>' +
      '<button id="rts-terms-modal-accept" type="button" style="padding: 8px 25px; background: #1a7efb; color: #fff; border: none; border-radius: 4px; cursor: pointer;" disabled>Accept & Continue</button>' +
      "</div>";

    modal.appendChild(content);
    document.body.appendChild(modal);

    var checkbox = document.getElementById("rts-terms-modal-checkbox");
    var acceptBtn = document.getElementById("rts-terms-modal-accept");
    var cancelBtn = document.getElementById("rts-terms-modal-cancel");

    checkbox.addEventListener("change", function () {
      acceptBtn.disabled = !this.checked;
    });

    acceptBtn.addEventListener("click", function () {
      if (!checkbox.checked) return;

      var pageData = getQrPageData();
      jQuery.ajax({
        type: "POST",
        url: rts_ajax.ajax_url,
        data: {
          action: "rts_accept_qr_terms",
          participant_id: pageData.participant_id,
          nonce: rts_ajax.nonce,
        },
        dataType: "json",
        success: function () {
          window.rtsQrPageData = window.rtsQrPageData || {};
          window.rtsQrPageData.needs_terms = false;
          modal.remove();
          if (typeof callback === "function") callback();
        },
        error: function () {
          modal.remove();
          if (typeof callback === "function") callback();
        },
      });
    });

    cancelBtn.addEventListener("click", function () {
      modal.remove();
    });

    modal.addEventListener("click", function (e) {
      if (e.target === modal) {
        modal.remove();
      }
    });
  }

  function rtsShowTermsModal(callback) {
    var pageData = getQrPageData();
    if (!pageData.needs_terms) {
      if (typeof callback === "function") callback();
      return;
    }

    jQuery.ajax({
      type: "POST",
      url: rts_ajax.ajax_url,
      data: {
          action: "rts_check_qr_terms",
        participant_id: pageData.participant_id,
        nonce: rts_ajax.nonce,
      },
      dataType: "json",
      success: function (response) {
        if (response && response.success) {
          createTermsModal(response.data.terms_html || "", callback);
        } else if (typeof callback === "function") {
          callback();
        }
      },
      error: function () {
        if (typeof callback === "function") callback();
      },
    });
  }

  function rtsUpdateCardName() {
    var pageData = getQrPageData();
    var nameField = document.getElementById("rts-card-name");
    var status = document.getElementById("rts-name-status");

    if (!nameField || !status) return;

    var name = (nameField.value || "").trim();
    if (name.length < 2) {
      status.textContent = "Name must be at least 2 characters";
      status.style.color = "#dc3545";
      return;
    }
    if (name.length > 50) {
      status.textContent = "Name must be less than 50 characters";
      status.style.color = "#dc3545";
      return;
    }
    if (/[0-9]{10,}/.test(name) || /(.)\1{5,}/.test(name) || /^[0-9]+$/.test(name)) {
      status.textContent = "Please enter a valid name";
      status.style.color = "#dc3545";
      return;
    }

    status.textContent = "Updating...";
    status.style.color = "#1a7efb";

    jQuery.ajax({
      type: "POST",
      url: rts_ajax.ajax_url,
      dataType: "json",
      data: {
          action: "rts_update_card_name",
        participant_id: pageData.participant_id,
        display_name: name,
        nonce: rts_ajax.nonce,
      },
      success: function (response) {
        if (response && response.success) {
          status.textContent = "Name updated!";
          status.style.color = "#28a745";
          if (response.data && response.data.card_url) {
            updateQrCardPreview(response.data.card_url);
          }
          window.rtsQrPageData = window.rtsQrPageData || {};
          window.rtsQrPageData.needs_terms = true;
        } else {
          status.textContent = (response && response.data) || "Failed to update";
          status.style.color = "#dc3545";
        }
      },
      error: function () {
        status.textContent = "Error updating name";
        status.style.color = "#dc3545";
      },
    });
  }

  function rtsDownloadQRCard() {
    rtsShowTermsModal(function () {
      var pageData = getQrPageData();
      var buttonState = getQrActionButton('[onclick="rtsDownloadQRCard()"]', "Download QR Card");
      if (buttonState.element.length) {
        buttonState.element.html("Generating...").prop("disabled", true);
      }

      jQuery.ajax({
        type: "POST",
        url: rts_ajax.ajax_url,
        dataType: "json",
        data: {
          action: "rts_generate_qr_card",
          participant_id: pageData.participant_id,
          nonce: rts_ajax.nonce,
        },
        success: function (response) {
          if (response && response.success && response.data && response.data.card_url) {
            updateQrCardPreview(response.data.card_url);
            var link = document.createElement("a");
            link.download = "run-the-seas-qr-card.png";
            link.href = response.data.card_url;
            link.target = "_blank";
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
          } else {
            alert("Error generating QR card. Please try again.");
          }
        },
        error: function () {
          alert("Error generating QR card. Please try again.");
        },
        complete: function () {
          if (buttonState.element.length) {
            buttonState.element.html(buttonState.originalText).prop("disabled", false);
          }
        },
      });
    });
  }

  function rtsShareQRCard() {
    rtsShowTermsModal(function () {
      var pageData = getQrPageData();
      var buttonState = getQrActionButton('[onclick="rtsShareQRCard()"]', "Share QR Card");
      if (buttonState.element.length) {
        buttonState.element.html("Preparing...").prop("disabled", true);
      }

      jQuery.ajax({
        type: "POST",
        url: rts_ajax.ajax_url,
        dataType: "json",
        data: {
          action: "rts_generate_qr_card",
          participant_id: pageData.participant_id,
          nonce: rts_ajax.nonce,
        },
        success: function (response) {
          if (response && response.success && response.data && response.data.card_url) {
            updateQrCardPreview(response.data.card_url);
            fetch(response.data.card_url)
              .then(function (res) {                
                return res.blob();
              })
              .then(function (blob) {                
                var file = new File([blob], "run-the-seas-qr-card.png", { type: "image/png" });                
                if (navigator.share && navigator.canShare) {
                  var shareData = {
                    title: "Join Run The Seas!",
                    text: "Scan my QR code to join Run The Seas as a Community Ambassador!",
                    files: [file],
                  };
                  if (navigator.canShare(shareData)) {
                    return navigator.share(shareData);
                  }
                  throw new Error("Cannot share this file type");
                }

                if (confirm("Share not available. Download the QR card image instead?")) {
                  var link = document.createElement("a");
                  link.download = "run-the-seas-qr-card.png";
                  link.href = response.data.card_url;
                  link.target = "_blank";
                  document.body.appendChild(link);
                  link.click();
                  document.body.removeChild(link);
                }
              })
              .catch(function () {
                if (confirm("Share not available. Download the QR card image instead?")) {
                  var link = document.createElement("a");
                  link.download = "run-the-seas-qr-card.png";
                  link.href = response.data.card_url;
                  link.target = "_blank";
                  document.body.appendChild(link);
                  link.click();
                  document.body.removeChild(link);
                }
              });
          } else {
            alert("Error generating QR card. Please try again.");
          }
        },
        error: function () {
          alert("Error generating QR card. Please try again.");
        },
        complete: function () {
          if (buttonState.element.length) {
            buttonState.element.html(buttonState.originalText).prop("disabled", false);
          }
        },
      });
    });
  }

  window.rtsUpdateCardName = rtsUpdateCardName;
  window.rtsDownloadQRCard = rtsDownloadQRCard;
  window.rtsShareQRCard = rtsShareQRCard;
  window.rtsCopyLink = rtsCopyLink;
  window.rtsShowTermsModal = rtsShowTermsModal;
  window.createTermsModal = createTermsModal;
  window.rtsTrackShareEvent = rtsTrackShareEvent;

  // ============================================
  // COLLECT ANSWERS FROM FORM
  // ============================================

  function collectAllFormAnswers($form) {
    var answers = {};

    if (!$form || !$form.length) {
      $form = $(".fluentform form");
    }

    if (!$form || !$form.length) {
      console.warn("Form not found for collecting answers");
      return answers;
    }

    $form.find("input, select, textarea").each(function () {
      var $field = $(this);
      var fieldName = $field.attr("name");

      if (!fieldName) return;

      var type = $field.attr("type");
      if (type === "submit" || type === "button" || type === "hidden") return;

      var value = "";

      if (type === "checkbox") {
        var checkedValues = [];
        $form.find('input[name="' + fieldName + '"]:checked').each(function () {
          var val = $(this).val();
          if (val !== "__ff_other_checkbox__") {
            checkedValues.push(val);
          }
        });
        var $otherInput = $field
          .closest(".ff-el-group")
          .find(".ff-other-input-wrapper input");
        if ($otherInput.length && $otherInput.val()) {
          checkedValues.push($otherInput.val());
        }
        value = checkedValues.length > 0 ? checkedValues : "";
      } else if (type === "radio") {
        var $checked = $form.find('input[name="' + fieldName + '"]:checked');
        if ($checked.length) {
          value = $checked.val() || "";
        }
      } else if (type === "file") {
        var files = $field[0].files;
        if (files && files.length > 0) {
          var fileNames = [];
          for (var i = 0; i < files.length; i++) {
            fileNames.push(files[i].name);
          }
          value = fileNames.length === 1 ? fileNames[0] : fileNames;
        }
      } else {
        value = $field.val() || "";
      }

      if (
        value !== "" &&
        value !== undefined &&
        value !== null &&
        value !== false
      ) {
        var label = getQuestionLabel($field) || fieldName;
        var cleanLabel = label.replace(/^\[|\]$/g, "").replace(/_/g, " ");
        cleanLabel = cleanLabel.charAt(0).toUpperCase() + cleanLabel.slice(1);
        var questionKey = fieldName + "_" + getFieldStepNumber($field, $form);

        answers[questionKey] = {
          fieldName: fieldName,
          value: value,
          field: $field,
          type: type || $field.prop("tagName").toLowerCase(),
          label: cleanLabel,
          originalValue: value,
        };
      }
    });

    rtsReviewData.answers = answers;

    console.log("📋 Total fields collected:", Object.keys(answers).length);

    return answers;
  }

  // ============================================
  // LOCATION FUNCTIONS (kept as before)
  // ============================================

  function getAccurateLocation(trackingId, formId) {
    if (!navigator.geolocation) {
      console.log("⚠️ Geolocation not supported, using IP fallback");
      fallbackToIPGeolocation(trackingId, formId);
      return;
    }

    console.log("📍 Requesting accurate location from browser...");

    navigator.geolocation.getCurrentPosition(
      function (position) {
        var locationData = {
          lat: position.coords.latitude,
          lng: position.coords.longitude,
          accuracy: position.coords.accuracy,
          altitude: position.coords.altitude || null,
          heading: position.coords.heading || null,
          speed: position.coords.speed || null,
        };

        console.log("✅ Accurate location obtained:", locationData);

        $.ajax({
          type: "POST",
          url: rts_ajax.ajax_url,
          data: {
            action: "rts_update_location",
            tracking_id: trackingId,
            tracking_token: rtsTracking.tracking_token,
            form_id: formId,
            lat: locationData.lat,
            lng: locationData.lng,
            accuracy: locationData.accuracy,
            nonce: rts_ajax.nonce,
          },
          success: function (response) {
            if (response.success) {
              console.log("✅ Accurate location saved to server");
            } else {
              console.error("❌ Failed to save accurate location:", response);
            }
          },
          error: function (xhr, status, error) {
            console.error("❌ Error saving location:", error);
          },
        });
      },
      function (error) {
        console.log("⚠️ Browser geolocation error:", error.message);
        console.log("🔄 Falling back to IP geolocation...");
        fallbackToIPGeolocation(trackingId, formId);
      },
      {
        enableHighAccuracy: true,
        timeout: 10000,
        maximumAge: 60000,
      },
    );
  }

  function fallbackToIPGeolocation(trackingId, formId) {
    console.log("📍 Using IP geolocation fallback...");

    $.ajax({
      type: "POST",
      url: rts_ajax.ajax_url,
      data: {
        action: "rts_geo_ip_fallback",
        tracking_id: trackingId,
        tracking_token: rtsTracking.tracking_token,
        form_id: formId,
        nonce: rts_ajax.nonce,
      },
      success: function (response) {
        if (response.success) {
          console.log("✅ IP geolocation fallback saved");
        } else {
          console.error("❌ IP geolocation fallback failed:", response);
        }
      },
      error: function (xhr, status, error) {
        console.error("❌ IP geolocation error:", error);
      },
    });
  }

  function requestLocationIfActive(trackingId, formId) {
    var locationAsked = sessionStorage.getItem(
      "rts_location_asked_" + trackingId,
    );
    if (locationAsked) {
      console.log("📍 Location already requested for this session");
      return;
    }

    var locationGranted = sessionStorage.getItem(
      "rts_location_granted_" + trackingId,
    );
    if (locationGranted === "denied") {
      console.log("📍 User previously denied location, using IP fallback");
      fallbackToIPGeolocation(trackingId, formId);
      return;
    }

    getAccurateLocation(trackingId, formId);
    sessionStorage.setItem("rts_location_asked_" + trackingId, "true");
  }

  // ============================================
  // REFERRAL FUNCTIONS (kept as before)
  // ============================================

  function getReferralParams() {
    var params = new URLSearchParams(window.location.search);
    return {
      code:
        params.get("ref") ||
        params.get("referral") ||
        params.get("referral_code") ||
        "",
      source:
        params.get("source") ||
        params.get("utm_source") ||
        params.get("referral_source") ||
        "",
    };
  }

  function storeReferralParams() {
    var params = getReferralParams();
    if (params.code) {
      if (rtsTracking.cookie_consent) {
        sessionStorage.setItem("rts_referral_code", params.code);
      }
      rtsTracking.referral_code = params.code;
    }
    if (params.source) {
      if (rtsTracking.cookie_consent) {
        sessionStorage.setItem("rts_referral_source", params.source);
      }
      rtsTracking.referral_source = params.source;
    }
    console.log("Referral params detected:", params);
  }

  function captureReferralParams() {
    var params = getReferralParams();
    rtsTracking.referral_code = params.code;
    rtsTracking.referral_source = params.source;
    return;

    var urlParams = new URLSearchParams(window.location.search);
    var refCode = urlParams.get("ref");
    var utmSource = urlParams.get("utm_source");
    var utmMedium = urlParams.get("utm_medium");
    var utmCampaign = urlParams.get("utm_campaign");

    if (refCode) {
      sessionStorage.setItem("rts_referral_code", refCode);
      sessionStorage.setItem("rts_referral_source", utmSource || "direct");

      console.log("📌 Referral detected:", refCode, "Source:", utmSource);

      if (!sessionStorage.getItem("rts_referral_notified")) {
        setTimeout(function () {
          var message =
            '<div style="background: #e3f2fd; padding: 15px; border-radius: 8px; margin: 10px 0; border-left: 4px solid #1a7efb;">' +
            '<p style="margin: 0; color: #1565c0;">🎉 <strong>Welcome!</strong> You were referred by a Founding Runner. ' +
            "Complete the survey and register to claim your $100 credit!</p>" +
            "</div>";
          $(".fluentform").before(message);
          sessionStorage.setItem("rts_referral_notified", "true");
        }, 1000);
      }
    }
  }

  // ============================================
  // TRACKING FUNCTIONS
  // ============================================

  function trackQuestion(
    formId,
    fieldName,
    fieldValue,
    questionLabel,
    questionType,
    step,
    questionKey,
  ) {
    if (!rtsTracking.is_active) {
      console.log("❌ Track question - not active");
      return;
    }
    if (rtsTracking.is_completed) {
      console.log("❌ Track question - already completed");
      return;
    }
    if (!rtsTracking.tracking_id || !rtsTracking.tracking_started) {
      console.log("❌ Track question - no tracking ID");
      return;
    }

    var answerValue = fieldValue;
    if (Array.isArray(fieldValue)) {
      answerValue = JSON.stringify(fieldValue);
    }

    if (fieldValue === null || fieldValue === undefined) {
      answerValue = "";
    }

    rtsReviewData.fieldStepMap[questionKey] = step;
    rtsReviewData.answers[questionKey] = {
      fieldName: fieldName,
      value: fieldValue,
      label: questionLabel,
      type: questionType,
      step: step,
    };

    console.log(
      "📤 Tracking question:",
      fieldName,
      "Type:",
      questionType,
      "Step:",
      step,
    );

    $.ajax({
      type: "POST",
      url: rts_ajax.ajax_url,
      data: {
        action: "rts_track_question_answer",
        form_id: formId,
        tracking_id: rtsTracking.tracking_id,
        tracking_token: rtsTracking.tracking_token,
        question_id: fieldName,
        answer: answerValue,
        question_label: questionLabel,
        question_type: questionType,
        step: step,
        nonce: rts_ajax.nonce,
      },
      success: function (response) {
        console.log("✅ Tracked question:", fieldName, "Step:", step);
      },
      error: function (xhr, status, error) {
        console.error("❌ AJAX error:", error);
      },
    });
  }

  function trackEmail(email) {
    if (!email || email.trim() === "") return;
    if (rtsTracking.email_tracked) return;
    if (rtsTracking.is_completed) return;
    if (!rtsTracking.is_active) {
      console.log("❌ Survey not active - skipping email tracking");
      return;
    }
    if (!rtsTracking.tracking_id || !rtsTracking.tracking_started) {
      console.log("No tracking ID yet, waiting...");
      setTimeout(function () {
        if (
          rtsTracking.tracking_id &&
          rtsTracking.tracking_started &&
          rtsTracking.is_active
        ) {
          trackEmail(email);
        }
      }, 1000);
      return;
    }

    var formId = rtsTracking.form_id;
    var currentStep = rtsTracking.current_step || 1;
    var fieldName = "email";
    var questionLabel = "Email";
    var questionType = "email";
    var questionKey = "email_" + currentStep;

    var trimmedEmail = email.trim();
    rtsTracking.email = trimmedEmail;
    rtsTracking.email_tracked = true;
    rtsTracking.answered_questions[questionKey] = trimmedEmail;

    console.log("📤 Tracking email:", trimmedEmail, "Step:", currentStep);

    $.ajax({
      type: "POST",
      url: rts_ajax.ajax_url,
      data: {
        action: "rts_track_question_answer",
        form_id: formId,
        tracking_id: rtsTracking.tracking_id,
        tracking_token: rtsTracking.tracking_token,
        question_id: fieldName,
        answer: trimmedEmail,
        question_label: questionLabel,
        question_type: questionType,
        step: currentStep,
        nonce: rts_ajax.nonce,
      },
      success: function (response) {
        console.log("✅ Email tracked successfully:", trimmedEmail);
      },
      error: function (xhr, status, error) {
        console.error("❌ Email tracking AJAX error:", error);
        rtsTracking.email_tracked = false;
        delete rtsTracking.answered_questions[questionKey];
      },
    });
  }

  // ============================================
  // SURVEY STATUS FUNCTIONS
  // ============================================

  function checkSurveyStatus() {
    var $form = $(".fluentform");

    if (!$form.length) {
      //console.log("⏳ Waiting for Fluent Form to load...");
      setTimeout(function () {
        checkSurveyStatus();
      }, 500);
      return;
    }

    var formId = $form.find("form").data("form_id");
    if (!formId) {
      console.log("⚠️ Form found but no form_id. Waiting...");
      setTimeout(function () {
        checkSurveyStatus();
      }, 500);
      return;
    }

    rtsTracking.form_id = formId;
    //console.log("✅ Fluent Form found! Form ID:", formId);

    $form.hide();

    $.ajax({
      type: "POST",
      url: rts_ajax.ajax_url,
      data: {
        action: "rts_check_survey_status",
        form_id: formId,
        nonce: rts_ajax.nonce,
      },
      dataType: "json",
      success: function (response) {
        console.log("Status check response:", response);
        rtsTracking.status_checked = true;

        if (!response.success) {
          console.error("Failed to check survey status:", response);
          $form.show();
          return;
        }

        var data = response.data;
        console.log("Survey Status Data:", data);

        var isActive = false;
        var isExcluded = false;
        var message = "";
        var className = "";

        if (data.status && data.status.excluded) {
          isExcluded = true;
          console.log(
            "🚫 Survey is EXCLUDED from tracking - showing form without tracking",
          );
          $form.show();
          $form.closest(".fluentform").show();
          $form.parents(".fluentform_wrapper").show();
          return;
        } else if (data.status && data.status.class === "active") {
          isActive = true;
          rtsTracking.is_active = true;
          console.log("✅ Survey is ACTIVE - showing form with tracking");
        } else if (data.status && data.status.class === "upcoming") {
          message =
            "This survey will be available on " + data.start_date + " (UTC)";
          className = "rts-survey-upcoming";
          console.log("⏳ Survey is SCHEDULED");
        } else if (data.status && data.status.class === "ended") {
          message = "This survey has ended on " + data.end_date + " (UTC)";
          className = "rts-survey-ended";
          console.log("❌ Survey has ENDED");
        } else if (data.status && data.status.class === "inactive") {
          message = "This survey is currently not available.";
          className = "rts-survey-inactive";
          console.log("❌ Survey is INACTIVE");
        } else {
          message = "This survey is currently not available.";
          className = "rts-survey-inactive";
          console.log("❌ Survey status unknown");
        }

        if (!isActive && !isExcluded) {
          var noticeHtml =
            '<div class="rts-survey-notice ' +
            className +
            '">' +
            "<h2>" +
            message +
            "</h2>" +
            "<p>Current UTC Time: " +
            (data.current_time_utc || "Unknown") +
            "</p>" +
            "</div>";
          $form.html(noticeHtml);
          $form.show();
        } else if (isActive) {
          $form.show();
          $form.closest(".fluentform").show();
          $form.parents(".fluentform_wrapper").show();

          if (!rtsTracking.tracking_started) {
            requestSurveyCookieConsent(function (accepted) {
              rtsTracking.session_id = generateSessionId(accepted);
              if (accepted) {
                storeReferralParams();
              }
              startTracking(formId);
            });
          }
        }
      },
      error: function (xhr, status, error) {
        console.error("Failed to check survey status:", error);
        $form.show();
      },
    });
  }

  function startTracking(formId) {
    if (!rtsTracking.is_active) {
      console.log("❌ Survey is not active - skipping tracking");
      return;
    }

    if (rtsTracking.tracking_started || rtsTracking.tracking_start_requested) {
      console.log("Tracking already started with ID:", rtsTracking.tracking_id);
      return;
    }

    rtsTracking.tracking_start_requested = true;

    console.log("Starting fresh tracking for form:", formId);
    rtsReviewData.answers = {};
    rtsReviewData.fieldStepMap = {};

    var referralCode =
      rtsTracking.referral_code ||
      (rtsTracking.cookie_consent ? sessionStorage.getItem("rts_referral_code") || "" : "");
    var referralSource =
      rtsTracking.referral_source ||
      (rtsTracking.cookie_consent ? sessionStorage.getItem("rts_referral_source") || "" : "");

    $.ajax({
      type: "POST",
      url: rts_ajax.ajax_url,
      data: {
        action: "rts_track_survey_start",
        form_id: formId,
        session_id: rtsTracking.session_id,
          referral_code: referralCode,
          referral_source: referralSource,
          cookie_consent: rtsTracking.cookie_consent ? "accepted" : "",
          nonce: rts_ajax.nonce,
      },
      success: function (response) {
        if (response.success) {
          rtsTracking.tracking_id = response.data.tracking_id;
          rtsTracking.tracking_token = response.data.tracking_token || "";
          rtsTracking.tracking_started = true;
          rtsTracking.form_started = true;

          console.log("Tracking started with NEW ID:", rtsTracking.tracking_id);
          console.log("Referral Code:", referralCode);
          console.log("Referral Source:", referralSource);

          requestLocationIfActive(rtsTracking.tracking_id, formId);

          if (rtsTracking.email && !rtsTracking.email_tracked) {
            trackEmail(rtsTracking.email);
          }
        } else {
          rtsTracking.tracking_start_requested = false;
          console.error("Failed to start tracking:", response);
        }
      },
      error: function (xhr, status, error) {
        rtsTracking.tracking_start_requested = false;
        console.error("AJAX error starting tracking:", error);
      },
    });
  }

  // ============================================
  // REVIEW FUNCTIONS - SIMPLIFIED
  // ============================================

  function showReviewScreen(trackingId, formId, email) {
    console.log("📋 Showing review screen");
    rtsTracking.is_review_mode = true;
    rtsReviewData.isReviewMode = true;
    rtsReviewData.reviewSessionActive = true;

    // Hide the form
    $(".fluentform").hide();

    // Use the tracked answer cache so each item keeps its original step
    var allAnswers = rtsReviewData.answers || {};

    // Build answers HTML
    var answersHtml = "";
    var answerCount = 0;

    for (var fieldName in allAnswers) {
      var ans = allAnswers[fieldName];
      if (
        !ans ||
        ans.value === "" ||
        ans.value === undefined ||
        ans.value === null
      )
        continue;

      answerCount++;
      var questionFieldName = ans.fieldName || fieldName;
      var displayValue = ans.value;
      if (Array.isArray(displayValue)) {
        displayValue = displayValue.join(", ");
      }
      if (typeof displayValue === "object") {
        displayValue = JSON.stringify(displayValue);
      }

      answersHtml += '<div class="rts-review-item" style="';
      answersHtml +=
        "display: flex; justify-content: space-between; align-items: center;";
      answersHtml += "padding: 8px 12px; background: #fff; border-radius: 6px;";
      answersHtml +=
        "margin-bottom: 6px; border-left: 3px solid #1a7efb; cursor: pointer;";
      answersHtml += "transition: all 0.2s ease;";
      answersHtml +=
        '" data-field="' +
        questionFieldName +
        '" data-step="' +
        (ans.step || 1) +
        '" onclick="rtsGoToField(\'' +
        questionFieldName +
        "', " +
        (ans.step || 1) +
        ')">';
      answersHtml += '<div style="flex: 1;">';
      answersHtml +=
        '<strong style="font-size: 12px; color: #333;">' +
        (ans.label || questionFieldName) +
        "</strong>";
      answersHtml +=
        '<div style="font-size: 13px; color: #555; margin-top: 2px;">' +
        displayValue +
        "</div>";
      answersHtml += "</div>";
      answersHtml +=
        '<span style="color: #1a7efb; font-size: 14px; margin-left: 10px;">✏️ Edit</span>';
      answersHtml += "</div>";
    }

    // Build the review HTML
    var reviewHtml =
      '<div id="rts-review-overlay" style="position: relative; max-width: 800px; margin: 30px auto; padding: 30px; background: #fff; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1);">';
    reviewHtml += '<div style="text-align: center;">';
    reviewHtml += '<div style="font-size: 48px; margin-bottom: 10px;">📋</div>';
    reviewHtml +=
      '<h2 style="color: #1a7efb; margin: 0;">Review Your Answers</h2>';
    reviewHtml +=
      '<p style="color: #666; margin-bottom: 20px;">Please review your answers below. Click on any answer to edit it.</p>';
    reviewHtml += "</div>";

    if (answerCount > 0) {
      reviewHtml +=
        '<div style="max-height: 400px; overflow-y: auto; margin: 15px 0;">' +
        answersHtml +
        "</div>";
    } else {
      reviewHtml +=
        '<div style="padding: 30px; text-align: center; background: #fff3cd; border-radius: 8px; border: 1px solid #ffc107;">';
      reviewHtml +=
        '<p style="color: #856404; font-size: 16px;">⚠️ No answers found to review.</p>';
      reviewHtml +=
        '<p style="color: #856404; font-size: 13px;">Please go back and answer the questions first.</p>';
      reviewHtml += '<button onclick="rtsGoBackToForm()" style="';
      reviewHtml +=
        'padding: 8px 20px; background: #1a7efb; color: #fff; border: none; border-radius: 4px; cursor: pointer; margin-top: 10px;">';
      reviewHtml += "⬅️ Go Back to Form</button>";
      reviewHtml += "</div>";
    }

    // Options
    reviewHtml +=
      '<div style="margin-top: 25px; padding-top: 20px; border-top: 2px solid #e9ecef;">';
    reviewHtml +=
      '<p style="text-align: center; color: #555; font-size: 15px; margin-bottom: 15px;">';
    reviewHtml += "What would you like to do next?</p>";

    reviewHtml +=
      '<div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">';

    reviewHtml +=
      "<button onclick=\"rtsSubmitAndGoToRegistration('" +
      trackingId +
      "', '" +
      formId +
      '\')" style="';
    reviewHtml +=
      'padding: 14px 35px; background: #28a745; color: #fff; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; min-width: 200px;">';
    reviewHtml += "✅ Submit</button>";

    reviewHtml += '<button onclick="rtsGoBackToForm()" style="';
    reviewHtml +=
      'padding: 14px 35px; background: #1a7efb; color: #fff; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; min-width: 200px;">';
    reviewHtml += "✏️ Edit Answers</button>";

    reviewHtml += "</div>";

    reviewHtml +=
      '<p style="text-align: center; color: #999; font-size: 12px; margin-top: 15px;">';
    reviewHtml += '💡 Click "Edit Answers" to go back and change any answers. ';
    reviewHtml +=
      '"Submit" will complete the survey and show you how to claim your rewards.</p>';
    reviewHtml += "</div>";
    reviewHtml += "</div>";

    // Insert the review HTML
    var $formWrapper = $(".fluentform_wrapper");
    if ($formWrapper.length) {
      $formWrapper.after(reviewHtml);
    } else {
      $(".fluentform").after(reviewHtml);
    }

    // Add hover effect for review items
    $(".rts-review-item")
      .on("mouseenter", function () {
        $(this).css("background", "#f0f7ff");
        $(this).css("transform", "translateX(5px)");
      })
      .on("mouseleave", function () {
        $(this).css("background", "#fff");
        $(this).css("transform", "translateX(0)");
      });

    renderReturnToReviewButton();
  }

  // Go back to form - SIMPLIFIED
  window.rtsGoBackToForm = function () {
    console.log("⬅️ Going back to form");
    rtsTracking.is_review_mode = false;
    rtsReviewData.isReviewMode = false;

    // Remove the review overlay
    $("#rts-review-overlay").remove();
    renderReturnToReviewButton();

    // Show the form
    $(".fluentform").show();
    $(".fluentform").closest(".fluentform").show();
    $(".fluentform").parents(".fluentform_wrapper").show();

    // Let Fluent Form handle the display - just scroll to form
    $("html, body").animate(
      {
        scrollTop: $(".fluentform").offset().top - 50,
      },
      300,
    );
  };

  window.rtsReturnToReview = function () {
    if (!rtsTracking.tracking_id || !rtsTracking.form_id) {
      return;
    }

    var email =
      rtsTracking.email ||
      $(".fluentform form").find('input[type="email"]').val() ||
      "";
    $("#rts-review-return-bar").remove();
    showReviewScreen(rtsTracking.tracking_id, rtsTracking.form_id, email);
  };

  function rtsNavigateToStep(targetStep, callback) {
    var $form = $(".fluentform form");

    if (!$form.length) {
      if (typeof callback === "function") {
        callback();
      }
      return;
    }

    var desiredStep = Math.max(1, parseInt(targetStep, 10) || 1);
    var guard = 0;

    var move = function () {
      var currentStep = getCurrentStep($form);
      if (currentStep === desiredStep) {
        if (typeof callback === "function") {
          callback();
        }
        return;
      }

      guard++;
      if (guard > 100) {
        console.warn("RTS: Step navigation timed out for step", desiredStep);
        if (typeof callback === "function") {
          callback();
        }
        return;
      }

      var directionSelector =
        currentStep < desiredStep ? ".ff-btn-next" : ".ff-btn-prev";
      var $activeStep = $form.find(".ff-step-body .fluentform-step.active").first();
      var $button = $activeStep.length
        ? $activeStep.find(directionSelector).first()
        : $();

      if (!$button.length) {
        $button = $form.find(directionSelector).first();
      }

      if (!$button.length) {
        if (typeof callback === "function") {
          callback();
        }
        return;
      }

      $button.trigger("click");
      setTimeout(move, 300);
    };

    // When Fluent Forms' clickable step titles are enabled, use their native
    // direct-navigation behaviour. This avoids visually and programmatically
    // stepping through every question while returning from the review screen.
    var $directStepTitle = $form.find(
      ".ff-step-titles [data-step-number='" + (desiredStep - 1) + "']",
    ).first();
    if ($directStepTitle.length) {
      var directJumpStarted = false;
      if (typeof window.rtsSurveyGoToStep === "function") {
        directJumpStarted = window.rtsSurveyGoToStep($form.get(0), desiredStep);
      }
      if (!directJumpStarted) {
        // The review screen is only shown after every step has been visited,
        // so its native Fluent Forms title is a valid direct target.
        $directStepTitle.trigger("click");
        directJumpStarted = true;
      }

      var directChecks = 0;
      var finishDirectJump = function () {
        directChecks++;
        if (getCurrentStep($form) === desiredStep || directChecks >= 12) {
          if (typeof callback === "function") {
            callback();
          }
          return;
        }
        setTimeout(finishDirectJump, 75);
      };
      setTimeout(finishDirectJump, 75);
      return;
    }

    move();
  }

  window.rtsGoToField = function (fieldName, stepNumber) {
    console.log("RTS: Going to field", fieldName, "step", stepNumber);

    var $form = $(".fluentform form");
    if (!$form.length) {
      rtsGoBackToForm();
      return;
    }

    var targetStep = parseInt(stepNumber, 10) || getCurrentStep($form);
    renderStepJumpOverlay(targetStep, fieldName);

    rtsNavigateToStep(targetStep, function () {
      $("#rts-review-overlay").remove();
      rtsTracking.is_review_mode = false;
      rtsReviewData.isReviewMode = false;
      removeStepJumpOverlay();

      $(".fluentform").show();
      $(".fluentform").closest(".fluentform").show();
      $(".fluentform").parents(".fluentform_wrapper").show();

      renderReturnToReviewButton();

      var $stepContainer = getStepContainer($form, targetStep);
      var $field = $stepContainer.length
        ? $stepContainer.find('[name="' + fieldName + '"]')
        : $form.find('[name="' + fieldName + '"]');
      if (!$field.length) {
        $field = $stepContainer.length
          ? $stepContainer.find('[name="' + fieldName + '[]"]')
          : $form.find('[name="' + fieldName + '[]"]');
      }

      if ($field.length) {
        var $targetField = $field.first();
        $targetField.focus();
        $targetField.css("border-color", "#1a7efb");
        $targetField.css("box-shadow", "0 0 0 2px rgba(26, 126, 251, 0.2)");
        $targetField.css("background-color", "#f0f7ff");

        $("html, body").animate(
          {
            scrollTop: $targetField.offset().top - 60,
          },
          500,
        );

        setTimeout(function () {
          $targetField.css("border-color", "");
          $targetField.css("box-shadow", "");
          $targetField.css("background-color", "");
        }, 3000);
      }
    });
  };

  // Submit the survey, then show the reward claim page before registration.
  window.rtsSubmitAndGoToRegistration = function (trackingId, formId) {
    console.log("📤 Submitting survey and opening reward claim page");

    var $btn = $('[onclick*="rtsSubmitAndGoToRegistration"]');
    var originalText = $btn.text();
    $btn.text("⏳ Submitting...").prop("disabled", true);

    var loadingHtml =
      '<div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 99999; display: flex; justify-content: center; align-items: center;">';
    loadingHtml +=
      '<div style="background: #fff; padding: 30px; border-radius: 12px; text-align: center;">';
    loadingHtml +=
      '<div class="spinner is-active" style="float: none; margin: 0 auto 15px auto;"></div>';
    loadingHtml +=
      '<p style="font-size: 16px; color: #333; margin: 0;">Submitting your answers...</p>';
    loadingHtml += "</div></div>";
    $("body").append(loadingHtml);

    $.ajax({
      type: "POST",
      url: rts_ajax.ajax_url,
      data: {
        action: "rts_complete_survey",
        tracking_id: trackingId,
        tracking_token: rtsTracking.tracking_token,
        form_id: formId,
        final_step: rtsTracking.current_step || 1,
        nonce: rts_ajax.nonce,
      },
      dataType: "json",
      success: function (response) {
        $("#rts-review-overlay").remove();
        $("#rts-review-return-bar").remove();
        removeStepJumpOverlay();
        $("body").find(".spinner").closest('div[style*="fixed"]').remove();

        if (response.success) {
          rtsTracking.is_completed = true;
          rtsReviewData.reviewSessionActive = false;
          console.log("✅ Survey completed successfully!");

          window.location.assign(
            response.data.redirect_url ||
              buildRegistrationUrl(
                response.data.tracking_id || trackingId,
                formId,
                rtsTracking.tracking_token,
              )
          );
        } else {
          alert("Error submitting survey. Please try again.");
          $btn.text(originalText).prop("disabled", false);
        }
      },
      error: function (xhr, status, error) {
        $("#rts-review-overlay").remove();
        $("#rts-review-return-bar").remove();
        removeStepJumpOverlay();
        $("body").find(".spinner").closest('div[style*="fixed"]').remove();
        rtsReviewData.reviewSessionActive = false;
        alert("Error submitting survey. Please try again.");
        $btn.text(originalText).prop("disabled", false);
        console.error("Submission error:", error);
      },
    });
  };

  // ============================================
  // INTERCEPT FORM SUBMISSION
  // ============================================

  // Intercept the form submit button click
  $(document).on("click", ".fluentform .ff-btn-submit", function (e) {
    if (!rtsTracking.is_active) return;

    var $btn = $(this);
    var $form = $btn.closest("form");
    var formId = $form.data("form_id");

    if (formId != rtsTracking.form_id) return;

    // If we're already in review mode, allow the normal submission
    if (rtsTracking.is_review_mode || rtsReviewData.isReviewMode) {
      console.log("📋 Already in review mode, allowing submission");
      return;
    }

    // Prevent the default form submission
    e.preventDefault();
    e.stopPropagation();

    console.log("🛑 Intercepted form submission - showing review screen");

    // Get email from the form
    var email = "";
    var $emailField = $form.find('input[type="email"]');
    if ($emailField.length) {
      email = $emailField.val() || "";
    }
    if (!email && rtsTracking.email) {
      email = rtsTracking.email;
    }

    // Show the review screen
    showReviewScreen(rtsTracking.tracking_id, rtsTracking.form_id, email);
  });

  // Helper function to track all answers before review
  function trackAllAnswers($form) {
    console.log("📤 Tracking all answers before review");

    $form.find("input, select, textarea").each(function () {
      var $field = $(this);
      var fieldName = $field.attr("name");
      if (!fieldName) return;

      var type = $field.attr("type");
      if (type === "submit" || type === "button" || type === "hidden") return;

      var value = "";
      var questionType = type || "text";

      if (type === "checkbox") {
        var values = [];
        $form.find('input[name="' + fieldName + '"]:checked').each(function () {
          var val = $(this).val();
          if (val !== "__ff_other_checkbox__") {
            values.push(val);
          }
        });
        value = values.length > 0 ? values : "";
        questionType = "checkbox";
      } else if (type === "radio") {
        var $checked = $form.find('input[name="' + fieldName + '"]:checked');
        if ($checked.length) {
          value = $checked.val() || "";
        }
        questionType = "radio";
      } else {
        value = $field.val() || "";
        if (type === "email") questionType = "email";
        else if (type === "number") questionType = "number";
        else if (type === "file") questionType = "file";
      }

      if (value !== "" && value !== undefined && value !== null) {
        var label = getQuestionLabel($field);
        var currentStep = getFieldStepNumber($field, $form);
        var questionKey = fieldName + "_" + currentStep;

        // Track each question
        trackQuestion(
          rtsTracking.form_id,
          fieldName,
          value,
          label,
          questionType,
          currentStep,
          questionKey,
        );
      }
    });
  }

  // ============================================
  // EVENT HANDLERS
  // ============================================

  // Textarea tracking
  $(document).on("input", ".fluentform textarea", function () {
    if (!rtsTracking.is_active) return;
    if (rtsTracking.is_review_mode) return;

    var $field = $(this);
    var $form = $field.closest(".fluentform");
    var formId = $form.find("form").data("form_id");

    if (formId != rtsTracking.form_id) return;
    if (!rtsTracking.tracking_id || !rtsTracking.tracking_started) return;
    if (rtsTracking.is_completed) return;
    if ($field.closest(".ff-other-input-wrapper").length) return;

    var currentStep = getCurrentStep($form);
    var fieldName = $field.attr("name");
    var fieldValue = $field.val();

    if (!fieldName) return;
    if (!fieldValue || fieldValue.trim() === "") return;

    var questionKey = fieldName + "_" + currentStep;

    if (textareaDebounceTimer) {
      clearTimeout(textareaDebounceTimer);
      textareaDebounceTimer = null;
    }

    var currentValue = fieldValue;
    var lastTrackedValue = textareaLastValue[questionKey] || "";

    if (currentValue === lastTrackedValue) {
      return;
    }

    textareaDebounceTimer = setTimeout(function () {
      var latestValue = $field.val();
      if (!latestValue || latestValue.trim() === "") {
        return;
      }

      var storedLastValue = textareaLastValue[questionKey] || "";
      if (latestValue === storedLastValue) {
        console.log("⏭️ Textarea value unchanged since last track");
        return;
      }

      var questionLabel = getQuestionLabel($field);

      textareaLastValue[questionKey] = latestValue;
      rtsTracking.answered_questions[questionKey] = latestValue;

      trackQuestion(
        formId,
        fieldName,
        latestValue,
        questionLabel,
        "textarea",
        currentStep,
        questionKey,
      );

      textareaDebounceTimer = null;
    }, 800);
  });

  // Text input tracking
  $(document).on(
    "input",
    ".fluentform input[type='text'], .fluentform input[type='tel'], .fluentform input[type='url'], .fluentform input[type='search'], .fluentform input[type='password']",
    function () {
      if (!rtsTracking.is_active) return;
      if (rtsTracking.is_review_mode) return;

      if ($(this).attr("type") === "email") return;

      var nameAttr = $(this).attr("name") || "";
      if (
        nameAttr.includes("first_name") ||
        nameAttr.includes("last_name") ||
        nameAttr.includes("full_name")
      )
        return;
      if (
        nameAttr.includes("address") ||
        nameAttr.includes("street") ||
        nameAttr.includes("city") ||
        nameAttr.includes("state") ||
        nameAttr.includes("zip") ||
        nameAttr.includes("postal")
      )
        return;

      var $field = $(this);
      var $form = $field.closest(".fluentform");
      var formId = $form.find("form").data("form_id");

      if (formId != rtsTracking.form_id) return;
      if (!rtsTracking.tracking_id || !rtsTracking.tracking_started) return;
      if (rtsTracking.is_completed) return;

      var currentStep = getCurrentStep($form);
      var fieldName = $field.attr("name");
      var fieldValue = $field.val();

      if (!fieldName) return;
      if (!fieldValue || fieldValue.trim() === "") return;

      var questionKey = fieldName + "_" + currentStep;

      if (inputDebounceTimers[questionKey]) {
        clearTimeout(inputDebounceTimers[questionKey]);
        delete inputDebounceTimers[questionKey];
      }

      var lastValue = inputLastValue[questionKey] || "";
      if (fieldValue === lastValue) {
        return;
      }

      inputDebounceTimers[questionKey] = setTimeout(function () {
        var latestValue = $field.val();
        if (!latestValue || latestValue.trim() === "") {
          return;
        }

        var storedLastValue = inputLastValue[questionKey] || "";
        if (latestValue === storedLastValue) {
          return;
        }

        var questionLabel = getQuestionLabel($field);

        inputLastValue[questionKey] = latestValue;
        rtsTracking.answered_questions[questionKey] = latestValue;

        trackQuestion(
          formId,
          fieldName,
          latestValue,
          questionLabel,
          "text",
          currentStep,
          questionKey,
        );

        delete inputDebounceTimers[questionKey];
      }, 500);
    },
  );

  // Email tracking
  $(document).on("input", ".fluentform input[type='email']", function () {
    if (!rtsTracking.is_active) return;
    if (rtsTracking.is_review_mode) return;

    var $field = $(this);
    var $form = $field.closest(".fluentform");
    var formId = $form.find("form").data("form_id");

    if (formId != rtsTracking.form_id) return;

    var email = $field.val();

    if (emailDebounceTimer) {
      clearTimeout(emailDebounceTimer);
      emailDebounceTimer = null;
    }

    if (!email || email.trim() === "") {
      return;
    }

    emailDebounceTimer = setTimeout(function () {
      var latestEmail = $field.val();
      if (!latestEmail || latestEmail.trim() === "") {
        return;
      }

      var trimmedEmail = latestEmail.trim();

      if (!trimmedEmail.includes("@") || !trimmedEmail.includes(".")) {
        console.log("⏭️ Invalid email format, skipping");
        return;
      }

      if (rtsTracking.email === trimmedEmail) {
        console.log("⏭️ Email unchanged, skipping");
        return;
      }

      rtsTracking.email = trimmedEmail;
      rtsTracking.email_tracked = false;

      console.log("📧 Tracking email:", trimmedEmail);
      trackEmail(trimmedEmail);

      emailDebounceTimer = null;
    }, 500);
  });

  // Other event handlers (select, radio, checkbox, etc.)
  $(document).on("change", ".fluentform select", function () {
    if (!rtsTracking.is_active) return;
    if (rtsTracking.is_review_mode) return;

    var $field = $(this);
    var $form = $field.closest(".fluentform");
    var formId = $form.find("form").data("form_id");

    if (formId != rtsTracking.form_id) return;
    if (!rtsTracking.tracking_id || !rtsTracking.tracking_started) return;
    if (rtsTracking.is_completed) return;

    var currentStep = getCurrentStep($form);
    rtsTracking.current_step = currentStep;

    var fieldName = $field.attr("name");
    var fieldValue = $field.val();
    var questionLabel = getQuestionLabel($field);
    var questionType = "select";

    if (fieldName && fieldValue && fieldValue !== "") {
      var questionKey = fieldName + "_" + currentStep;
      trackQuestion(
        formId,
        fieldName,
        fieldValue,
        questionLabel,
        questionType,
        currentStep,
        questionKey,
      );
    }
  });

  $(document).on("change", ".fluentform input[type='radio']", function () {
    if (!rtsTracking.is_active) return;
    if (rtsTracking.is_review_mode) return;

    var $field = $(this);
    var $form = $field.closest(".fluentform");
    var formId = $form.find("form").data("form_id");

    if (formId != rtsTracking.form_id) return;
    if (!rtsTracking.tracking_id || !rtsTracking.tracking_started) return;
    if (!$field.is(":checked")) return;
    if (rtsTracking.is_completed) return;

    var currentStep = getCurrentStep($form);
    rtsTracking.current_step = currentStep;

    var fieldName = $field.attr("name");
    var fieldValue = $field.val();
    var questionLabel = getQuestionLabel($field);
    var questionType = "radio";

    if (fieldName && fieldValue) {
      var questionKey = fieldName + "_" + currentStep;
      if (rtsTracking.answered_questions[questionKey]) {
        delete rtsTracking.answered_questions[questionKey];
      }
      trackQuestion(
        formId,
        fieldName,
        fieldValue,
        questionLabel,
        questionType,
        currentStep,
        questionKey,
      );
    }
  });

  $(document).on("change", ".fluentform input[type='checkbox']", function () {
    if (!rtsTracking.is_active) return;
    if (rtsTracking.is_review_mode) return;

    var $field = $(this);
    var $form = $field.closest(".fluentform");
    var formId = $form.find("form").data("form_id");

    if (formId != rtsTracking.form_id) return;
    if (!rtsTracking.tracking_id || !rtsTracking.tracking_started) {
      // Queue the tracking for when tracking is ready
      if (!rtsTracking._pending_checkbox_changes) {
        rtsTracking._pending_checkbox_changes = [];
      }
      rtsTracking._pending_checkbox_changes.push({
        field: $field,
        formId: formId,
        timestamp: Date.now(),
      });

      if (!rtsTracking._processing_pending) {
        rtsTracking._processing_pending = true;
        var checkPending = function () {
          if (rtsTracking.tracking_id && rtsTracking.tracking_started) {
            rtsTracking._processing_pending = false;
            if (rtsTracking._pending_checkbox_changes) {
              rtsTracking._pending_checkbox_changes.forEach(function (item) {
                item.field.trigger("change");
              });
              rtsTracking._pending_checkbox_changes = [];
            }
          } else {
            setTimeout(checkPending, 500);
          }
        };
        checkPending();
      }
      return;
    }
    if (rtsTracking.is_completed) return;

    var currentStep = getCurrentStep($form);
    rtsTracking.current_step = currentStep;

    var $group = $field.closest(".ff-el-group");
    var $checkboxes = $group.find('input[type="checkbox"]');
    var checkedValues = [];
    var hasOther = false;

    $checkboxes.each(function () {
      if ($(this).is(":checked")) {
        var val = $(this).val();
        if (val === "__ff_other_checkbox__") {
          hasOther = true;
        } else {
          checkedValues.push(val);
        }
      }
    });

    var questionLabel = getQuestionLabel($field);
    var fieldName = $field.attr("name");

    var $otherInput = $group.find(".ff-other-input-wrapper input");
    if (hasOther && $otherInput.length && $otherInput.val()) {
      checkedValues.push($otherInput.val());
    }

    if (fieldName) {
      var questionKey = fieldName + "_" + currentStep;
      var answerToTrack = checkedValues.length > 0 ? checkedValues : "";

      trackQuestion(
        formId,
        fieldName,
        answerToTrack,
        questionLabel,
        "checkbox",
        currentStep,
        questionKey,
      );
    }
  });

  // Abandonment handler
  $(window).on("beforeunload", function () {
    if (!rtsTracking.is_active) return;
    if (
      rtsTracking.is_submitting ||
      rtsTracking.is_completed ||
      abandonmentSent ||
      rtsTracking.completion_attempted
    ) {
      return;
    }

    if (rtsTracking.tracking_id > 0 && rtsTracking.tracking_started) {
      abandonmentSent = true;
      console.log("Tracking abandonment for ID:", rtsTracking.tracking_id);

      var formData = new FormData();
      formData.append("action", "rts_track_abandonment");
      formData.append("tracking_id", rtsTracking.tracking_id);
      formData.append("tracking_token", rtsTracking.tracking_token);
      formData.append("step", rtsTracking.current_step);
      formData.append("nonce", rts_ajax.nonce);

      if (navigator.sendBeacon) {
        navigator.sendBeacon(rts_ajax.ajax_url, formData);
      } else {
        $.ajax({
          type: "POST",
          url: rts_ajax.ajax_url,
          data: {
            action: "rts_track_abandonment",
            tracking_id: rtsTracking.tracking_id,
            tracking_token: rtsTracking.tracking_token,
            step: rtsTracking.current_step,
            nonce: rts_ajax.nonce,
          },
          async: false,
        });
      }
    }
  });

  // ============================================
  // INITIALIZATION
  // ============================================

  var initialReferralParams = getReferralParams();
  rtsTracking.referral_code = initialReferralParams.code;
  rtsTracking.referral_source = initialReferralParams.source;

  function initSurvey() {
    console.log("🔍 Initializing survey check...");
    var $form = $(".fluentform");
    if ($form.length) {
      $form.hide();
    }
    checkSurveyStatus();
  }

  initSurvey();

  $(document).on("fluentform_loaded", function () {
    console.log("🔄 Fluent Form loaded event detected");
    setTimeout(function () {
      checkSurveyStatus();
    }, 300);
  });

  $(window).on("load", function () {
    console.log("📄 Window fully loaded, checking for form...");
    setTimeout(function () {
      checkSurveyStatus();
    }, 300);
  });

  // ============================================
  // STYLES
  // ============================================

  if (!$("#rts-review-styles").length) {
    var styles = `
            <style id="rts-review-styles">
                .rts-review-item:hover {
                    background: #f0f7ff !important;
                    transform: translateX(5px);
                    transition: all 0.2s ease;
                }
                .rts-review-final {
                    animation: fadeIn 0.5s ease;
                }
                .rts-review-final button:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
                }
                .rts-review-final button:disabled {
                    opacity: 0.6;
                    cursor: not-allowed;
                    transform: none !important;
                }
                @keyframes fadeIn {
                    from { opacity: 0; transform: translateY(20px); }
                    to { opacity: 1; transform: translateY(0); }
                }
            </style>`;
    $("head").append(styles);
  }

  console.log("✅ RTS Tracking initialized");
  console.log("✅ Review functionality initialized");
});

/** Capture the complete Elementor leaderboard as a printable/shareable PNG. */
(function () {
  "use strict";

  var transparentPixel =
    "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=";

  function getCaptureTarget() {
    return (
      document.querySelector("[data-rts-leaderboard-export-root]") ||
      document.querySelector(".rts-leaderboard-page") ||
      document.querySelector('.elementor[data-elementor-type="wp-page"]') ||
      document.querySelector("main .elementor") ||
      document.querySelector(".entry-content") ||
      document.querySelector("main") ||
      document.querySelector(".rts-live-leaderboard") ||
      document.querySelector(".rts-leaderboard-standings")
    );
  }

  function setBusy(actionBar, busy, message) {
    Array.prototype.forEach.call(actionBar.querySelectorAll("button"), function (button) {
      button.disabled = busy;
    });
    var status = actionBar.querySelector(".rts-leaderboard-print-action__status");
    if (status) {
      status.textContent = message || "";
    }
  }

  function imageFilename() {
    return "run-the-seas-leaderboard-" + new Date().toISOString().slice(0, 10) + ".png";
  }

  function ensureDomToImage() {
    if (window.domtoimage) {
      return Promise.resolve(window.domtoimage);
    }

    var configured = window.rts_ajax || {};
    var urls = [configured.dom_to_image_url, configured.dom_to_image_fallback_url].filter(
      function (url, index, list) {
        return url && list.indexOf(url) === index;
      },
    );

    function loadNext(index) {
      if (window.domtoimage) {
        return Promise.resolve(window.domtoimage);
      }
      if (index >= urls.length) {
        return Promise.reject(new Error("The leaderboard image exporter did not load."));
      }

      return new Promise(function (resolve, reject) {
        var script = document.createElement("script");
        script.src = urls[index] + (urls[index].indexOf("?") === -1 ? "?" : "&") + "rts=" + Date.now();
        script.async = true;
        script.onload = function () {
          if (window.domtoimage) {
            resolve(window.domtoimage);
          } else {
            reject(new Error("Exporter script loaded without its expected API."));
          }
        };
        script.onerror = function () {
          reject(new Error("Unable to load exporter script."));
        };
        document.head.appendChild(script);
      }).catch(function () {
        return loadNext(index + 1);
      });
    }

    return loadNext(0);
  }

  function nextPaint() {
    return new Promise(function (resolve) {
      window.requestAnimationFrame(function () {
        window.requestAnimationFrame(resolve);
      });
    });
  }

  function waitForCaptureImages(target) {
    var images = target.querySelectorAll("img");
    var jobs = Array.prototype.map.call(images, function (image) {
      var lazySource =
        image.getAttribute("data-src") ||
        image.getAttribute("data-lazy-src") ||
        image.getAttribute("data-original");

      image.loading = "eager";
      if ((!image.complete || image.naturalWidth === 0) && lazySource) {
        image.src = lazySource;
      }

      if (image.complete && image.naturalWidth > 0) {
        return typeof image.decode === "function" ? image.decode().catch(function () {}) : Promise.resolve();
      }

      return new Promise(function (resolve) {
        var timeout = window.setTimeout(resolve, 4000);
        function finish() {
          window.clearTimeout(timeout);
          image.removeEventListener("load", finish);
          image.removeEventListener("error", finish);
          resolve();
        }
        image.addEventListener("load", finish);
        image.addEventListener("error", finish);
      });
    });

    return Promise.all(jobs);
  }

  function inlineCaptureImages(target) {
    var restoredImages = [];

    Array.prototype.forEach.call(target.querySelectorAll("img"), function (image) {
      if (!image.complete || image.naturalWidth === 0 || image.naturalHeight === 0) {
        return;
      }

      try {
        var canvas = document.createElement("canvas");
        canvas.width = image.naturalWidth;
        canvas.height = image.naturalHeight;
        var context = canvas.getContext("2d");
        context.drawImage(image, 0, 0);
        var dataUrl = canvas.toDataURL("image/png");

        restoredImages.push({
          image: image,
          src: image.getAttribute("src"),
          srcset: image.getAttribute("srcset"),
          sizes: image.getAttribute("sizes"),
        });
        image.removeAttribute("srcset");
        image.removeAttribute("sizes");
        image.src = dataUrl;
      } catch (error) {
        // Cross-origin avatars remain unchanged; dom-to-image will use its normal fallback.
      }
    });

    return function restoreImages() {
      restoredImages.forEach(function (saved) {
        if (saved.src === null) {
          saved.image.removeAttribute("src");
        } else {
          saved.image.setAttribute("src", saved.src);
        }
        if (saved.srcset === null) {
          saved.image.removeAttribute("srcset");
        } else {
          saved.image.setAttribute("srcset", saved.srcset);
        }
        if (saved.sizes === null) {
          saved.image.removeAttribute("sizes");
        } else {
          saved.image.setAttribute("sizes", saved.sizes);
        }
      });
    };
  }

  function prepareCaptureLayout(target) {
    var savedStyles = [];
    var savedNodes = [];

    function remember(element) {
      if (!element || element === target || savedNodes.indexOf(element) !== -1) {
        return false;
      }
      savedNodes.push(element);
      savedStyles.push(element.getAttribute("style"));
      return true;
    }

    function expand(element) {
      if (!element || element === target || savedNodes.indexOf(element) !== -1) {
        return;
      }
      var computed = window.getComputedStyle(element);
      var constrained =
        /auto|scroll/.test(computed.overflow + computed.overflowY) ||
        (computed.maxHeight && computed.maxHeight !== "none") ||
        (element.scrollHeight > element.clientHeight + 2);
      if (!constrained) {
        return;
      }
      remember(element);
      element.style.setProperty("height", "auto", "important");
      element.style.setProperty("max-height", "none", "important");
      element.style.setProperty("overflow", "visible", "important");
      element.style.setProperty("overflow-y", "visible", "important");
    }

    function findDecoratedAncestor(element) {
      var fallback = element.closest(".elementor-widget") || element;
      var containerFallback = null;
      var elementRect = element.getBoundingClientRect();
      var ancestor = element.parentElement;
      var levels = 0;

      while (ancestor && ancestor !== target && levels < 7) {
        var style = window.getComputedStyle(ancestor);
        var before = window.getComputedStyle(ancestor, "::before");
        var hasBackground =
          (style.backgroundImage && style.backgroundImage !== "none") ||
          (before.backgroundImage && before.backgroundImage !== "none");
        if (hasBackground) {
          return ancestor;
        }

        var rect = ancestor.getBoundingClientRect();
        if (
          !containerFallback &&
          ancestor.matches(".e-con, .elementor-section, .elementor-column") &&
          rect.height > elementRect.height * 3
        ) {
          containerFallback = ancestor;
        }
        ancestor = ancestor.parentElement;
        levels += 1;
      }

      return containerFallback || fallback;
    }

    Array.prototype.forEach.call(
      target.querySelectorAll(
        ".rts-trophy-milestones, .rts-leaderboard-standings, " +
          ".rts-live-leaderboard__standings, .rts-live-leaderboard__milestones",
      ),
      function (section) {
        var node = section;
        var levels = 0;
        while (node && node !== target && levels < 4) {
          expand(node);
          node = node.parentElement;
          levels += 1;
        }
      },
    );

    target.classList.add("rts-export-target");

    var milestoneList = target.querySelector(".rts-trophy-milestones");
    if (milestoneList) {
      var milestonePanel = findDecoratedAncestor(milestoneList);
      remember(milestonePanel);
      milestonePanel.style.setProperty("height", "auto", "important");
      milestonePanel.style.setProperty("max-height", "none", "important");
      milestonePanel.style.setProperty("overflow", "visible", "important");
      milestonePanel.style.setProperty("border-left", "2px solid #d69b23", "important");
      milestonePanel.style.setProperty("border-right", "2px solid #d69b23", "important");
      milestonePanel.style.setProperty("box-sizing", "border-box", "important");
    }

    var summary = target.querySelector(".rts-member-leaderboard-summary");
    if (summary) {
      var summaryBlock = findDecoratedAncestor(summary);
      var summaryRect = summaryBlock.getBoundingClientRect();
      var precedingBottom = 0;
      var candidates = target.querySelectorAll(
        ".elementor-widget, .rts-leaderboard-standings, .rts-leaderboard-how-it-works, " +
          ".rts-trophy-milestones",
      );

      Array.prototype.forEach.call(candidates, function (candidate) {
        if (
          candidate === summaryBlock ||
          candidate.contains(summaryBlock) ||
          summaryBlock.contains(candidate) ||
          candidate.closest(".rts-leaderboard-print-action")
        ) {
          return;
        }
        var rect = candidate.getBoundingClientRect();
        var overlap = Math.min(rect.right, summaryRect.right) - Math.max(rect.left, summaryRect.left);
        var minimumOverlap = Math.min(180, summaryRect.width * 0.25);
        if (overlap >= minimumOverlap && rect.bottom <= summaryRect.top + 1) {
          precedingBottom = Math.max(precedingBottom, rect.bottom);
        }
      });

      var summaryGap = summaryRect.top - precedingBottom;
      if (precedingBottom && summaryGap > 32) {
        remember(summaryBlock);
        var currentMargin = parseFloat(window.getComputedStyle(summaryBlock).marginTop) || 0;
        summaryBlock.style.setProperty("margin-top", currentMargin - (summaryGap - 14) + "px", "important");
      }
    }

    return function restoreLayout() {
      target.classList.remove("rts-export-target");
      savedNodes.forEach(function (node, index) {
        var style = savedStyles[index];
        if (style === null) {
          node.removeAttribute("style");
        } else {
          node.setAttribute("style", style);
        }
      });
    };
  }

  function getCaptureHeight(target) {
    if (target.matches(".rts-live-leaderboard, .rts-leaderboard-standings")) {
      return Math.max(target.scrollHeight, target.offsetHeight);
    }

    var targetRect = target.getBoundingClientRect();
    var contentBottom = 0;
    var nodes = target.querySelectorAll(
      ".elementor-widget, .rts-live-leaderboard, .rts-leaderboard-header, " +
        ".rts-leaderboard-podium, .rts-leaderboard-standings, .rts-trophy-milestones, " +
        ".rts-member-leaderboard-summary",
    );
    Array.prototype.forEach.call(nodes, function (node) {
      if (node.closest(".rts-leaderboard-print-action")) {
        return;
      }
      var computed = window.getComputedStyle(node);
      if (computed.display === "none" || computed.visibility === "hidden" || computed.position === "fixed") {
        return;
      }
      var rect = node.getBoundingClientRect();
      if (rect.width > 0 && rect.height > 0) {
        contentBottom = Math.max(contentBottom, rect.bottom - targetRect.top);
      }
    });

    var paddingBottom = parseFloat(window.getComputedStyle(target).paddingBottom) || 0;
    // Keep Elementor borders, shadows and pseudo-elements at the bottom from being clipped.
    return Math.max(1, Math.ceil(contentBottom + paddingBottom + 48));
  }

  function captureLeaderboard() {
    var target = getCaptureTarget();
    if (!target) {
      return Promise.reject(new Error("Leaderboard content was not found on this page."));
    }

    var scale = 1.5;
    var fontReady = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
    var restoreLayout = prepareCaptureLayout(target);
    var restoreImages = function () {};

    return Promise.all([fontReady, ensureDomToImage(), waitForCaptureImages(target)])
      .then(function (results) {
        restoreImages = inlineCaptureImages(target);
        return waitForCaptureImages(target).then(nextPaint).then(function () {
          var exporter = results[1];
          var width = Math.max(target.scrollWidth, target.offsetWidth);
          var height = getCaptureHeight(target);
          return exporter.toBlob(target, {
            bgcolor: "#061525",
            cacheBust: false,
            imagePlaceholder: transparentPixel,
            width: Math.round(width * scale),
            height: Math.round(height * scale),
            style: {
              transform: "scale(" + scale + ")",
              transformOrigin: "top left",
              width: width + "px",
              height: height + "px",
              minHeight: "0",
              overflow: "visible",
            },
            filter: function (node) {
              if (node.tagName === "IMG" && (!node.complete || node.naturalWidth === 0)) {
                return false;
              }
              return !(
                node.classList &&
                (node.classList.contains("rts-leaderboard-print-action") ||
                  node.classList.contains("elementor-location-header") ||
                  node.classList.contains("elementor-location-footer"))
              );
            },
          });
        });
      })
      .then(
        function (blob) {
          restoreImages();
          restoreLayout();
          return blob;
        },
        function (error) {
          restoreImages();
          restoreLayout();
          throw error;
        },
      );
  }

  function downloadBlob(blob) {
    var url = URL.createObjectURL(blob);
    var link = document.createElement("a");
    link.href = url;
    link.download = imageFilename();
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(function () {
      URL.revokeObjectURL(url);
    }, 1000);
  }

  function printBlob(blob, printWindow) {
    var url = URL.createObjectURL(blob);
    printWindow.document.open();
    printWindow.document.write(
      '<!doctype html><html><head><meta charset="utf-8"><title>Run The Seas Leaderboard</title>' +
        '<style>@page{margin:0}html,body{width:100%;height:100%;margin:0;background:#061525;overflow:hidden}' +
        'img{position:fixed;inset:0;width:100%;height:100%;object-fit:contain;' +
        '-webkit-print-color-adjust:exact;print-color-adjust:exact}</style></head>' +
        '<body><img src="' + url + '" alt="Run The Seas Leaderboard"></body></html>',
    );
    printWindow.document.close();
    printWindow.addEventListener("load", function () {
      printWindow.focus();
      printWindow.print();
    });
    printWindow.addEventListener("afterprint", function () {
      URL.revokeObjectURL(url);
      printWindow.close();
    });
  }

  function shareBlob(blob) {
    var file = new File([blob], imageFilename(), { type: "image/png" });
    if (navigator.share && (!navigator.canShare || navigator.canShare({ files: [file] }))) {
      return navigator.share({
        title: "Run The Seas Leaderboard",
        text: "Run The Seas Leaderboard",
        files: [file],
      });
    }
    downloadBlob(blob);
    return Promise.resolve();
  }

  document.addEventListener("click", function (event) {
    var videoButton = event.target.closest("[data-rts-video-id]");
    if (videoButton) {
      event.preventDefault();

      var video = document.getElementById(videoButton.getAttribute("data-rts-video-id"));
      var cover = document.getElementById(videoButton.getAttribute("data-rts-cover-id"));
      var section = videoButton.closest(".rts-captains-update");
      var status = section && section.querySelector(".rts-captains-update__status");

      if (!video) {
        return;
      }

      if (cover) {
        cover.hidden = true;
      }
      video.hidden = false;
      video.load();
      video.scrollIntoView({ behavior: "smooth", block: "center" });
      if (status) {
        status.textContent = "Loading Captain’s Update…";
      }

      video.addEventListener("canplay", function () {
        if (status) {
          status.textContent = "";
        }
      }, { once: true });
      video.addEventListener("error", function () {
        if (status) {
          status.textContent = "The video could not be loaded here. Please use “Open video in a new window”.";
        }
      }, { once: true });

      window.setTimeout(function () {
        if (status && video.readyState === 0) {
          status.textContent = "The video is still loading. You can use “Open video in a new window” below if needed.";
        }
      }, 7000);

      var playResult = video.play();
      if (playResult && typeof playResult.catch === "function") {
        playResult.catch(function () {
          if (status) {
            status.textContent = "Use the video controls to play, or open the video in a new window.";
          }
        });
      }
      return;
    }

    var button = event.target.closest("[data-rts-leaderboard-export]");
    if (!button) {
      return;
    }
    event.preventDefault();

    var action = button.getAttribute("data-rts-leaderboard-export");
    var actionBar = button.closest(".rts-leaderboard-print-action");
    var printWindow = null;
    if ("print" === action) {
      printWindow = window.open("", "rts-leaderboard-print", "width=1200,height=850");
      if (!printWindow) {
        window.alert("Please allow pop-ups for this site to print the leaderboard.");
        return;
      }
      printWindow.document.write("<p style='font-family:sans-serif'>Preparing leaderboard image...</p>");
    }

    setBusy(actionBar, true, "Preparing full-page image...");
    captureLeaderboard()
      .then(function (blob) {
        if ("print" === action) {
          printBlob(blob, printWindow);
        } else if ("share" === action) {
          return shareBlob(blob);
        } else {
          downloadBlob(blob);
        }
        return null;
      })
      .then(function () {
        setBusy(actionBar, false, "");
      })
      .catch(function (error) {
        if (printWindow && !printWindow.closed) {
          printWindow.close();
        }
        setBusy(actionBar, false, "Unable to create image.");
        window.alert(error && error.message ? error.message : "Unable to export the leaderboard.");
      });
  });
})();

(function () {
  "use strict";

  var log = document.querySelector(".rts-captains-log[data-messages-per-page]");
  if (!log || !window.matchMedia || !window.URL) {
    return;
  }

  var breakpoint = window.matchMedia("(max-width: 1080px)");

  function syncCaptainLogPageSize() {
    var desired = breakpoint.matches ? "2" : "5";
    if (log.getAttribute("data-messages-per-page") === desired) {
      return;
    }

    var url = new URL(window.location.href);
    if ("2" === desired) {
      url.searchParams.set("rts_log_per_page", "2");
    } else {
      url.searchParams.delete("rts_log_per_page");
    }
    url.searchParams.delete("rts_log_page");
    window.location.replace(url.toString());
  }

  syncCaptainLogPageSize();
  if (typeof breakpoint.addEventListener === "function") {
    breakpoint.addEventListener("change", syncCaptainLogPageSize);
  } else if (typeof breakpoint.addListener === "function") {
    breakpoint.addListener(syncCaptainLogPageSize);
  }
})();
