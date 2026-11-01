# eFacturiPlus pentru PrestaShop

Emite facturi în eFacturiPlus din comenzi și le trimite în e-Factura (SPV). PrestaShop 1.7.7 – 9.

1. În eFacturiPlus: Firma mea → Integrări și API → creează o cheie.
2. În PrestaShop: Module → încarcă arhiva, apoi Configurează: cheia, seria, starea comenzii.
3. Pentru facturi pe firmă, activează în magazin câmpurile „Companie” și „Cod fiscal” pe adresă (modul B2B). CUI-ul se ia din „Cod TVA”, iar numărul de la Registrul Comerțului din „Altele” (sau „DNI”).

Limite: cotele acceptate prin API sunt cele standard din România; comenzile cu altă cotă (inclusiv 0% la export) rămân nefacturate, cu motivul afișat în comandă. Rambursările parțiale se tratează în eFacturiPlus.
