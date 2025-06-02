#!/usr/bin/env python3
import asyncio
import argparse
import edge_tts
import sys

async def main():
    parser = argparse.ArgumentParser(description="Generate TTS using Edge TTS")
    parser.add_argument("--voice", required=True, help="Voice to use")
    parser.add_argument("--rate", default="+0%", help="Speech rate")
    parser.add_argument("--volume", default="+0%", help="Speech volume")
    parser.add_argument("--text-file", required=True, help="File containing text to speak")
    parser.add_argument("--output", required=True, help="Output audio file")
    
    try:
        args = parser.parse_args()
        
        # Debug output
        print(f"Voice: {args.voice}", file=sys.stderr)
        print(f"Rate: {args.rate}", file=sys.stderr)
        print(f"Volume: {args.volume}", file=sys.stderr)
        print(f"Text file: {args.text_file}", file=sys.stderr)
        print(f"Output: {args.output}", file=sys.stderr)
        
        # Read text from file
        with open(args.text_file, "r", encoding="utf-8") as f:
            text = f.read()
        
        print(f"Text length: {len(text)} characters", file=sys.stderr)
        
        # Generate speech
        communicate = edge_tts.Communicate(text, args.voice, rate=args.rate, volume=args.volume)
        await communicate.save(args.output)
        
        print("TTS generation completed successfully", file=sys.stderr)
        
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        sys.exit(1)

if __name__ == "__main__":
    asyncio.run(main())
