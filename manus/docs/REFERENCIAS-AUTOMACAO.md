# Referências técnicas de relatórios e notificações

A automação de envio pertence ao aplicativo PHP no cPanel. Não há envio real ou acesso ao servidor durante o desenvolvimento.

[PHPMailer](https://github.com/PHPMailer/PHPMailer) é uma biblioteca mantida para SMTP, suporta TLS, valida endereços e recomenda instalação via Composer. A documentação consultada indica a série 7 como atual. Não implementar SMTP ou MIME manualmente e não desabilitar validação TLS.

[Web Push PHP](https://github.com/web-push-libs/web-push-php) recomenda Composer, requer PHP 8.2+ e extensões mbstring, curl e openssl; gmp/bcmath melhoram desempenho. O servidor usa VAPID e uma PushSubscription do navegador. Payload é cifrado pela biblioteca. Push não deve transportar dados de humor em notificações e não é um alarme com horário garantido.

[Push API — MDN](https://developer.mozilla.org/en-US/docs/Web/API/Push_API) explica que mensagens são recebidas por service worker, que pode apresentar notificações mesmo sem a página aberta. Um timer numa página não substitui entrega push do servidor.

[Web Push — Apple](https://developer.apple.com/documentation/usernotifications/sending-web-push-notifications-in-web-apps-and-browsers) descreve suporte em web apps adicionados à Tela de Início em iOS/iPadOS 16.4 ou posterior. Permissão depende de ação direta do usuário. O sistema e as preferências de notificação podem adiar ou impedir a apresentação.
