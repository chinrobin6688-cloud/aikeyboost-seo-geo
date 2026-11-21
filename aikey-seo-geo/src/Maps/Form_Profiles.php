<?php
namespace AIKEY\SeoGeo\Maps;

use AIKEY\SeoGeo\Core\Settings;

defined( 'ABSPATH' ) || exit;

/** Lightweight per-site form profiles; existing no-argument shortcode remains default. */
final class Form_Profiles {
    public static function all(): array {
        $forms = Settings::section_get( 'map', 'forms', array() );
        return is_array( $forms ) ? $forms : array();
    }

    public static function get( string $id ): ?array {
        if ( '' === $id ) { return array( 'id'=>'', 'language'=>'zh', 'label'=>'默认表单' ); }
        $forms = self::all();
        if ( ! isset( $forms[ $id ] ) || ! is_array( $forms[ $id ] ) ) { return null; }
        return array_merge( $forms[ $id ], array( 'id'=>$id ) );
    }

    public static function valid_id( string $id ): bool {
        return (bool) preg_match( '/^[a-z][a-z0-9_-]{0,39}$/D', $id ) && 'default' !== $id;
    }

    public static function languages(): array {
        return array(
            'zh'=>'简体中文', 'en'=>'English', 'fr'=>'Français', 'ar'=>'العربية',
            'de'=>'Deutsch', 'es'=>'Español', 'ru'=>'Русский', 'pt'=>'Português',
        );
    }

    public static function normalize( string $id, array $raw ): array {
        $language = sanitize_key( (string) ( $raw['language'] ?? 'en' ) );
        if ( ! isset( self::languages()[ $language ] ) ) { $language = 'en'; }
        $values = array( 'language'=>$language );
        foreach ( array( 'label'=>100, 'company'=>200, 'address'=>300, 'heading'=>150, 'submit'=>80 ) as $key=>$length ) {
            $value = sanitize_text_field( (string) ( $raw[ $key ] ?? '' ) );
            $values[ $key ] = function_exists( 'mb_substr' )
                ? mb_substr( $value, 0, $length, 'UTF-8' ) : substr( $value, 0, $length );
        }
        $values['label'] = '' !== $values['label'] ? $values['label'] : $id;
        return $values;
    }

    /** Map UI labels follow the selected form language, not the WordPress locale. */
    public static function map_labels( string $lang ): array {
        static $labels = array(
            'zh'=>array( 'location'=>'企业位置', 'google'=>'打开 Google 地图', 'amap'=>'打开高德地图', 'baidu'=>'打开百度地图', 'missing'=>'请在 SEO&GEO → 基础设置 → 企业地图中填写展示地址，或在“企业实体 / Schema”中填写地址。' ),
            'en'=>array( 'location'=>'Company Location', 'google'=>'Open in Google Maps', 'missing'=>'Map address is not configured. Please contact the site administrator.' ),
            'fr'=>array( 'location'=>'Emplacement de l’entreprise', 'google'=>'Ouvrir dans Google Maps', 'missing'=>'Adresse non configurée. Veuillez contacter l’administrateur du site.' ),
            'ar'=>array( 'location'=>'موقع الشركة', 'google'=>'فتح في خرائط Google', 'missing'=>'عنوان الخريطة غير محدد. يرجى الاتصال بمسؤول الموقع.' ),
            'de'=>array( 'location'=>'Unternehmensstandort', 'google'=>'In Google Maps öffnen', 'missing'=>'Die Kartenadresse ist nicht eingerichtet. Bitte kontaktieren Sie die Website-Verwaltung.' ),
            'es'=>array( 'location'=>'Ubicación de la empresa', 'google'=>'Abrir en Google Maps', 'missing'=>'La dirección no está configurada. Contacte con el administrador.' ),
            'ru'=>array( 'location'=>'Местоположение компании', 'google'=>'Открыть в Google Картах', 'missing'=>'Адрес на карте не указан. Обратитесь к администратору сайта.' ),
            'pt'=>array( 'location'=>'Localização da empresa', 'google'=>'Abrir no Google Maps', 'missing'=>'O endereço do mapa não foi configurado. Entre em contato com o administrador.' ),
        );
        return $labels[ $lang ] ?? $labels['en'];
    }

    public static function messages( string $lang ): array {
        static $all = array(
            'zh'=>array('heading'=>'留下您的联系方式','name'=>'姓名','phone'=>'电话','email'=>'电子邮件','message'=>'留言','submit'=>'提交','name_help'=>'请输入您的姓名','phone_help'=>'请输入您的电话号码','email_help'=>'请输入有效的电子邮件地址','message_help'=>'请在此处输入您的留言或其他信息','success'=>'提交成功，我们会尽快与您联系。','invalid'=>'提交内容有误，请检查后重新提交。','rate'=>'提交过于频繁，请稍后再试。','storage'=>'暂时无法保存留言，请稍后再试。','security'=>'提交验证失败，请刷新页面后重试。','email_required'=>'请输入电子邮件地址。','message_required'=>'请填写留言内容。'),
            'en'=>array('heading'=>'Get in touch','name'=>'Name','phone'=>'Phone','email'=>'Email','message'=>'Message','submit'=>'Send message','name_help'=>'Your name','phone_help'=>'Your phone number','email_help'=>'Your email address','message_help'=>'How can we help?','success'=>'Thank you! We will contact you soon.','invalid'=>'Please check your information and try again.','rate'=>'Too many submissions. Please try again later.','storage'=>'Unable to save your message. Please try again later.','security'=>'Verification failed. Please refresh and try again.','email_required'=>'Please enter your email address.','message_required'=>'Please enter your message.'),
            'fr'=>array('heading'=>'Contactez-nous','name'=>'Nom','phone'=>'Téléphone','email'=>'E-mail','message'=>'Message','submit'=>'Envoyer','name_help'=>'Votre nom','phone_help'=>'Votre numéro de téléphone','email_help'=>'Votre adresse e-mail','message_help'=>'Comment pouvons-nous vous aider ?','success'=>'Merci ! Nous vous contacterons bientôt.','invalid'=>'Vérifiez vos informations et réessayez.','rate'=>'Trop de tentatives. Réessayez plus tard.','storage'=>'Enregistrement impossible. Réessayez plus tard.','security'=>'Échec de vérification. Actualisez la page.','email_required'=>'Veuillez saisir votre adresse e-mail.','message_required'=>'Veuillez saisir votre message.'),
            'ar'=>array('heading'=>'تواصل معنا','name'=>'الاسم','phone'=>'الهاتف','email'=>'البريد الإلكتروني','message'=>'الرسالة','submit'=>'إرسال','name_help'=>'اسمك','phone_help'=>'رقم هاتفك','email_help'=>'بريدك الإلكتروني','message_help'=>'كيف يمكننا مساعدتك؟','success'=>'شكراً لك! سنتواصل معك قريباً.','invalid'=>'يرجى مراجعة المعلومات والمحاولة مجدداً.','rate'=>'طلبات كثيرة. حاول لاحقاً.','storage'=>'تعذر حفظ الرسالة. حاول لاحقاً.','security'=>'فشل التحقق. يرجى تحديث الصفحة.','email_required'=>'يرجى إدخال بريدك الإلكتروني.','message_required'=>'يرجى كتابة رسالتك.'),
            'de'=>array('heading'=>'Kontaktieren Sie uns','name'=>'Name','phone'=>'Telefon','email'=>'E-Mail','message'=>'Nachricht','submit'=>'Senden','name_help'=>'Ihr Name','phone_help'=>'Ihre Telefonnummer','email_help'=>'Ihre E-Mail-Adresse','message_help'=>'Wie können wir helfen?','success'=>'Vielen Dank! Wir melden uns bald.','invalid'=>'Bitte prüfen Sie Ihre Angaben.','rate'=>'Zu viele Anfragen. Bitte später erneut versuchen.','storage'=>'Nachricht konnte nicht gespeichert werden.','security'=>'Verifizierung fehlgeschlagen. Bitte Seite neu laden.','email_required'=>'Bitte geben Sie Ihre E-Mail-Adresse ein.','message_required'=>'Bitte geben Sie Ihre Nachricht ein.'),
            'es'=>array('heading'=>'Contáctenos','name'=>'Nombre','phone'=>'Teléfono','email'=>'Correo electrónico','message'=>'Mensaje','submit'=>'Enviar','name_help'=>'Su nombre','phone_help'=>'Su teléfono','email_help'=>'Su correo electrónico','message_help'=>'¿En qué podemos ayudarle?','success'=>'¡Gracias! Nos pondremos en contacto pronto.','invalid'=>'Revise los datos e inténtelo de nuevo.','rate'=>'Demasiados envíos. Inténtelo más tarde.','storage'=>'No se pudo guardar el mensaje.','security'=>'Error de verificación. Actualice la página.','email_required'=>'Introduzca su correo electrónico.','message_required'=>'Escriba su mensaje.'),
            'ru'=>array('heading'=>'Свяжитесь с нами','name'=>'Имя','phone'=>'Телефон','email'=>'Эл. почта','message'=>'Сообщение','submit'=>'Отправить','name_help'=>'Ваше имя','phone_help'=>'Ваш номер телефона','email_help'=>'Ваш адрес электронной почты','message_help'=>'Чем можем помочь?','success'=>'Спасибо! Мы скоро свяжемся с вами.','invalid'=>'Проверьте данные и попробуйте снова.','rate'=>'Слишком много запросов. Попробуйте позже.','storage'=>'Не удалось сохранить сообщение.','security'=>'Ошибка проверки. Обновите страницу.','email_required'=>'Укажите адрес электронной почты.','message_required'=>'Введите ваше сообщение.'),
            'pt'=>array('heading'=>'Entre em contato','name'=>'Nome','phone'=>'Telefone','email'=>'E-mail','message'=>'Mensagem','submit'=>'Enviar','name_help'=>'Seu nome','phone_help'=>'Seu telefone','email_help'=>'Seu e-mail','message_help'=>'Como podemos ajudar?','success'=>'Obrigado! Entraremos em contato em breve.','invalid'=>'Verifique os dados e tente novamente.','rate'=>'Muitas tentativas. Tente mais tarde.','storage'=>'Não foi possível salvar a mensagem.','security'=>'Falha na verificação. Atualize a página.','email_required'=>'Informe seu endereço de e-mail.','message_required'=>'Escreva sua mensagem.'),
        );
        return $all[ $lang ] ?? $all['en'];
    }
}
