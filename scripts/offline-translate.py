#!/usr/bin/env python3

import json
import sys
from pathlib import Path

import ctranslate2
import sentencepiece
from argostranslate.tokenizer import BPETokenizer


def processor(model_path: Path, source: bool):
    bpe = model_path / "bpe.model"
    if bpe.exists():
        metadata = json.loads((model_path / "metadata.json").read_text(encoding="utf-8"))
        tokenizer = BPETokenizer(
            bpe,
            metadata["from_code"],
            metadata["to_code"],
        )

        return tokenizer.encode if source else tokenizer.decode

    dedicated = model_path / ("source.spm" if source else "target.spm")
    shared = model_path / "sentencepiece.model"
    path = dedicated if dedicated.exists() else shared
    if not path.exists():
        raise RuntimeError(f"SentencePiece model missing in {model_path}")

    sentence_processor = sentencepiece.SentencePieceProcessor(model_file=str(path))

    if source:
        return lambda value: sentence_processor.encode(value, out_type=str)

    return lambda tokens: sentence_processor.decode_pieces(tokens).replace("▁", " ").replace("_", " ").strip()


def main() -> None:
    if len(sys.argv) != 4:
        raise SystemExit("usage: offline-translate.py MODEL INPUT_JSON OUTPUT_JSON")

    model_path = Path(sys.argv[1]).resolve()
    input_path = Path(sys.argv[2]).resolve()
    output_path = Path(sys.argv[3]).resolve()
    values = json.loads(input_path.read_text(encoding="utf-8"))
    if not isinstance(values, list) or not all(isinstance(value, str) for value in values):
        raise RuntimeError("Input must be a JSON string array.")

    source_processor = processor(model_path, True)
    target_processor = processor(model_path, False)
    translator = ctranslate2.Translator(
        str(model_path / "model" if (model_path / "model").is_dir() else model_path),
        device="cpu",
        compute_type="int8",
    )
    model_configuration_path = model_path / "config.json"
    if not model_configuration_path.exists():
        model_configuration_path = model_path / "model" / "config.json"
    model_configuration = (
        json.loads(model_configuration_path.read_text(encoding="utf-8"))
        if model_configuration_path.exists()
        else {}
    )
    translations = []
    batch_size = 64
    for start in range(0, len(values), batch_size):
        batch = values[start : start + batch_size]
        tokenized = [source_processor(value) for value in batch]
        if model_configuration.get("model_type") == "marian":
            eos_token = model_configuration.get("eos_token", "</s>")
            tokenized = [tokens + [eos_token] for tokens in tokenized]
        results = translator.translate_batch(
            tokenized,
            beam_size=2,
            max_batch_size=batch_size,
            batch_type="tokens",
            replace_unknowns=True,
        )
        translations.extend(
            target_processor(result.hypotheses[0]) for result in results
        )

    output_path.write_text(
        json.dumps(translations, ensure_ascii=False),
        encoding="utf-8",
    )


if __name__ == "__main__":
    main()
