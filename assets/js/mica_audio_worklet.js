/**
 * MICA AudioWorklet Processor
 *
 * Captures raw PCM Float32 audio from the microphone and converts it
 * to Int16Array (16-bit PCM) chunks. Runs in the AudioWorklet scope
 * and posts converted buffers to the main thread via MessagePort.
 */
class MicaAudioCaptureProcessor extends AudioWorkletProcessor {
  constructor() {
    super();
    this._bufferSize = 4096;
    this._buffer = new Float32Array(this._bufferSize);
    this._writeIndex = 0;
    this._sampleRate = 48000;
  }

  process(inputs, outputs, parameters) {
    const input = inputs[0];
    if (!input || !input[0]) {
      return true;
    }

    const channelData = input[0];
    this._sampleRate = sampleRate;

    for (let i = 0; i < channelData.length; i++) {
      this._buffer[this._writeIndex++] = channelData[i];

      if (this._writeIndex >= this._bufferSize) {
        const pcm16 = this._float32ToInt16(this._buffer);
        this.port.postMessage({
          type: 'audio',
          data: pcm16.buffer,
          sampleRate: this._sampleRate,
        }, [pcm16.buffer]);
        this._writeIndex = 0;
      }
    }

    return true;
  }

  _float32ToInt16(float32Array) {
    const int16 = new Int16Array(float32Array.length);
    for (let i = 0; i < float32Array.length; i++) {
      let s = Math.max(-1, Math.min(1, float32Array[i]));
      int16[i] = s < 0 ? s * 0x8000 : s * 0x7FFF;
    }
    return int16;
  }
}

registerProcessor('mica-audio-capture', MicaAudioCaptureProcessor);
