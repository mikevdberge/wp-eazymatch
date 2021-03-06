<?php
if ( ! defined( 'EMOL_DIR' ) ) {
	die( 'no direct access' );
}

/**
 * inschrijfformulier... needs some improvements, handeling of this form is now done by the fakepost script
 *
 */
class emol_shortcode_react {

	var $captcha;

	/**
	 * determines what will happen
	 *
	 */
	function getContent() {

		if ( isset( $_POST['birthdate-year'] ) && isset( $_POST['birthdate-month'] ) && isset( $_POST['birthdate-day'] ) ) {
			//fetch birthdate parts
			$_POST['birthdate'] = $_POST['birthdate-year'] . '-' . $_POST['birthdate-month'] . '-' . $_POST['birthdate-day'];
		}

		$this->captcha = new emol_captcha();

		if ( emol_post_exists( 'EMOL_apply' ) ) {

			$secure = false;
			if ( emol_post_exists( 'emol_captcha_code' ) ) {
				if ( $this->captcha->isValid() || emol_session::isValidId( 'applicant_id' ) ) {
					$secure = true;
				}
			}

			if ( emol_post_exists( 'g-recaptcha-response' ) ) {

				$data_google = array(
					'secret'   => get_option( 'emol_frm_google_captcha_secret' ),
					'response' => emol_post( 'g-recaptcha-response' ),
					'remoteip' => $_SERVER['REMOTE_ADDR']
				);
				$options     = array(
					'http' => array(
						'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
						'method'  => 'POST',
						'content' => http_build_query( $data_google ),
					),
					"ssl"  => array(
						"verify_peer"      => false,
						"verify_peer_name" => false,
					)
				);
				$context     = stream_context_create( $options );
				$result      = file_get_contents( 'https://www.google.com/recaptcha/api/siteverify', false, $context );

				$result = json_decode( $result, true );
				if ( $result['success'] == true ) {
					$secure = true;
				}
			}

			if ( $secure == true ) {
				return $this->doReact();
			} else {
				$_POST['captcha-error'] = 1;

				return $this->createForm();
			}

		} else {

			return $this->createForm();

		}


	}


	/**
	 * when someone has hit the button
	 */
	function doReact() {
		$ws = $this->emolApi->get( 'company' );
		$wsTool = $this->emolApi->get( 'tool' );

		if ( ! emol_session::isValidId( 'company_id' ) ) {

			//create a array the way EazyMatch likes it
			$subscription = new emol_CompanyMutation();

			//set the Company
			$subscription->setCompany(
				null,
				emol_post( 'name' ),
				emol_post( 'profile' ),
				emol_post( 'companysize_id' ),
				emol_post( 'branche_id' ),
				emol_post( 'coc' )
			);

			//set addresses
			if ( isset( $_POST['street'] ) && $_POST['street'] != '' ) {

				$subscription->addAddress(
					null,
					null,
					null,
					null,
					emol_post( 'street' ),
					emol_post( 'housenumber' ),
					emol_post( 'extension' ),
					emol_post( 'zipcode' ),
					emol_post( 'city' )
				);

			} elseif ( isset( $_POST['zipcode'] ) && $_POST['zipcode'] != '' ) {

				$addrPiece = $wsTool->getAddressByZipcode( emol_post( 'zipcode' ) );

				$subscription->addAddress(
					null,
					$addrPiece['province_id'],
					$addrPiece['country_id'],
					$addrPiece['region_id'],
					$addrPiece['street'],
					emol_post( 'housenumber' ),
					emol_post( 'extension' ),
					$addrPiece['zipcode'],
					$addrPiece['city']
				);

			}

			//set the `contact` person
			$subscription->setPerson(
				null,
				emol_post( 'firstname' ),
				emol_post( 'middlename' ),
				emol_post( 'lastname' ),
				emol_post( 'birthdate' ),
				emol_post( 'email' ),
				emol_post( 'password' ),
				emol_post( 'gender' )
			);

			//set the Contact
			$subscription->setContact(
				null,
				emol_post( 'department' )
			);


			/**email**/
			$subscription->addEmailaddresses( null, null, emol_post( 'email' ) );

			/**phonenumber**/
			$subscription->addPhonenumber( null, null, emol_post( 'phonenumber' ) );

			/**PHOTO**/
			if ( isset( $_FILES['logo']['tmp_name'] ) && (string) $_FILES['logo']['tmp_name'] != '' ) {

				/**set the CV document**/
				$doc            = array();
				$doc['name']    = $_FILES['logo']['name'];
				$doc['content'] = base64_encode( file_get_contents( $_FILES['logo']['tmp_name'] ) );
				$doc['type']    = $_FILES['logo']['type'];

				/**set the logo**/
				$subscription->setLogo( $doc['name'], $doc['type'], $doc['content'] );
			}


			/**JOBDOC**/
			if ( isset( $_FILES['jobDocument']['tmp_name'] ) && ! empty( $_FILES['jobDocument']['tmp_name'] ) ) {

				/**set the CV document**/
				$doc            = array();
				$doc['name']    = $_FILES['jobDocument']['name'];
				$doc['content'] = base64_encode( file_get_contents( $_FILES['jobDocument']['tmp_name'] ) );
				$doc['type']    = $_FILES['jobDocument']['type'];

				/**set the docu**/
				$subscription->setJob( emol_post( 'jobName' ), $doc );

			} elseif ( emol_post( 'jobName' ) !== null ) {
				$subscription->setJob( emol_post( 'jobName' ), array() );
			}

			$url = $_SERVER['HTTP_HOST'];

			$subscription->setApplication(
				null,
				emol_post( 'applicantId' ),
				emol_post( 'motivation' ),
				$url
			);

			//create the workable postable array
			$postData = $subscription->createSubscription();

			//save the subscription to EazyMatch
			$ws->subscription( $postData );

			ob_clean();
			wp_redirect( get_bloginfo( 'wpurl' ) . '/' . get_option( 'emol_react_url_cv' ) . '/' . $this->applicantId . '/success/' );
			exit;

		} else {
			/*react to an applicant, notification will be sent to emol user*/
			$contactId = emol_session::get( 'contact_id' );

			if ( (int) $contactId > 0 ) {
				$success = $ws->reactToApplicant( emol_post( 'jobId' ), $contactId, emol_post( 'applicantId' ), emol_post( 'motivation' ), true );
			} else {
				echo "ERROR NO CONTACT";
				exit();
			}

			if ( $success == true ) {
				ob_clean();
				wp_redirect( get_bloginfo( 'wpurl' ) . '/' . get_option( 'emol_react_url_cv' ) . '/' . $this->applicantId . '/success/' );
				exit;
			} else {
				ob_clean();
				wp_redirect( get_bloginfo( 'wpurl' ) . '/' . get_option( 'emol_react_url_cv' ) . '/' . $this->applicantId . '/unsuccess/' );
				exit;
			}
		}
		exit();
	}


	/**
	 * creates the fake content
	 */
	function createForm( $defaultdata = array() ) {


		$api = eazymatch_connect();

		//check login?
		//if (get_option('emol_cv_secure') == 1 && !(emol_session::isContact()) && $this->applicantId !== 0) {
		//    $errorMesssage = '<div id="emol-error-no-access-cv-database"><a href="/' . get_option('emol_react_url_cv') . '/0/open">' . get_option('emol_react_cv_error_secure') . '</a></div>';
		//   return $errorMesssage;
		//}

		// remove auto line breaks
		remove_filter( 'the_content', 'wpautop' );

		//fillup default array of presets
		$data['name']        = '';
		$data['gender']      = '';
		$data['firstname']   = '';
		$data['lastname']    = '';
		$data['middlename']  = '';
		$data['city']        = '';
		$data['street']      = '';
		$data['zipcode']     = '';
		$data['housenumber'] = '';
		$data['extension']   = '';
		$data['email']       = '';
		$data['phonenumber'] = '';
		$data['department']  = '';
		$data['coc']         = '';
		$data['jobName']     = '';
		$data['motivation']  = '';


		//fill up default data
		if ( isset( $_POST ) && count( $_POST ) > 0 ) {
			$data = $_POST;
		}


		// prepare client resources
		emol_require::validation();
		emol_require::jqueryUi();

		//the react form
		$reactHtml = '
        <div id="emol-form-react" class="emol-form-div emol-form-table">
        <form method="post" action="/' . get_option( 'emol_react_url_cv' ) . '/0/open" id="emol-react-form" enctype="multipart/form-data">
        <input type="hidden" name="applicantId" value="' . $this->applicantId . '" />
        <input type="hidden" name="EMOL_react" value="1" />';

		$urlVars = explode( '/', $this->page_slug );
		if ( isset( $urlVars[2] ) && $urlVars[2] == 'success' ) {
			$reactHtml .= '<div class="emol-apply-row" id="emol-success-row">' . stripslashes( get_option( 'emol_react_success' ) ) . '</div>';
		} elseif ( isset( $urlVars[2] ) && $urlVars[2] == 'unsuccess' ) {
			$reactHtml .= '<div class="emol-apply-row" id="emol-error-row">' . EMOL_REACT_FAIL_MSG . '</div>';
		} else {
			include( EMOL_DIR . '/lib/emol/reactform.php' );
		}


		//finish up html
		$reactHtml .= '
        </form>
        </div>

        <script type="text/javascript">
        jQuery(function() {
            jQuery(\'input.datepicker\').datepicker({
                    dateFormat: \'yy-mm-dd\',
                    changeMonth: true,
                    changeYear: true,
                    showOn: \'both\',
                    yearRange: \'1915:2011\',
                    buttonImageOnly: true
                },jQuery.datepicker.regional[\'' . EMOL_REGIONAL . '\']);});
        jQuery(document).ready(function(){
        jQuery("#emol-react-form").validate({
                messages: {
                    phonenumber: { required: "' . EMOL_ERR_REQUIRED . '" },
                    lastname: { required: "' . EMOL_ERR_REQUIRED . '" },
                    firstname: { required: "' . EMOL_ERR_REQUIRED . '" },
                    email: { required: "' . EMOL_ERR_REQUIRED . '" },
                    cv: { required: "' . EMOL_ERR_REQUIRED . '" },
                    title: { required: "' . EMOL_ERR_REQUIRED . '" },
                    picture: { required: "' . EMOL_ERR_REQUIRED . '" },
                    contactvia: { required: "' . EMOL_ERR_REQUIRED . '" },
                    zipcode: {
                        minlength: "' . EMOL_ERR_MIN_CHAR_ZIPCODE . '",
                        required: "' . EMOL_ERR_REQUIRED . '"
                    },
                    birthdate: { required: "' . EMOL_ERR_REQUIRED . '" },
                    email: {
                        required: "' . EMOL_ERR_REQUIRED . '",
                        email: "' . EMOL_ERR_VALID_EMAIL . '"
                    }
                }
            });
        });
        </script>
        ';

		//return some html
		return $reactHtml;
	}
}

