"""Créer uniquement la plateforme Android avec votre Flutter local, sans écraser lib/main.dart."""
from pathlib import Path
import shutil
import subprocess
import tempfile

root = Path(__file__).resolve().parent
flutter = shutil.which('flutter')
if not flutter:
    raise SystemExit('Flutter absent : installez Flutter et relancez ce script.')
if (root / 'android').exists():
    raise SystemExit('Le dossier android existe déjà : aucune modification automatique effectuée.')
with tempfile.TemporaryDirectory(prefix='android-scaffold-', dir=root) as temporary:
    target = Path(temporary) / 'app'
    subprocess.run([flutter, 'create', '--platforms=android', '--project-name=cantine_mobile', '--org=ci.cantine', str(target)], check=True)
    shutil.copytree(target / 'android', root / 'android')
manifest = root / 'android/app/src/main/AndroidManifest.xml'
text = manifest.read_text(encoding='utf-8')
text = text.replace('<application', '<uses-permission android:name="android.permission.INTERNET"/>\n    <application', 1)
text = text.replace('android:label="cantine_mobile"', 'android:label="Ma cantine"')
manifest.write_text(text, encoding='utf-8')
print('Plateforme Android créée. Exécutez flutter pub get, flutter analyze, puis flutter build apk --release.')
