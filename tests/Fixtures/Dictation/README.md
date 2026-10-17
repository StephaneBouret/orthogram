# MP3 synthétique de test

`tone.mp3` : sinusoïde 440 Hz, deux secondes, mono 44,1 kHz, MP3 64 kbit/s.
Aucune voix, aucun enregistrement ou contenu de Caroline.

Généré localement une fois avec :

```text
ffmpeg -hide_banner -loglevel error -f lavfi -i sine=frequency=440:sample_rate=44100:duration=2 -ac 1 -codec:a libmp3lame -b:a 64k -map_metadata -1 tests/Fixtures/Dictation/tone.mp3
```

ffmpeg n’est nécessaire ni à l’application ni à l’exécution des tests. Les imports de test copient ce fichier dans un répertoire temporaire privé propre au processus, supprimé à sa sortie.

`DictationAudioFixture::longUpload()` construit aussi un MP3 temporaire de 305 625 ms : retrait des en-têtes ID3/Xing de cette fixture 64 kbit/s, puis répétition de ses trames audio 150 fois. Le fichier passe par le véritable import et son contrôle getID3 ; il sert aux chevauchements à 128 383 et 260 316 ms. Aucun encodeur supplémentaire, aucun enregistrement réel. Le fichier temporaire et le stockage isolé sont supprimés en fin de processus. Le serveur navigateur l’active uniquement avec `--long-audio`.
