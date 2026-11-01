# Testele clienților

Același scenariu pentru fiecare limbaj: cont, creare, dublură, citire, căutare, PDF, erori, cheie greșită, refuzul adreselor `http`, SPV, stornare, webhook, reîncercări și redirectări.

Se rulează **doar pe un API local**, cu o firmă de test: scenariul emite facturi. `run.sh` refuză alte adrese.

```bash
EFP_KEY=... EFP_URL=http://127.0.0.1:8811/api/parteneri/v1 bash run.sh
```
