/**
 * Records the microphone straight to a 16-bit mono WAV file.
 * MediaRecorder output differs per browser (WebM without duration on Chrome, MP4 on Safari, nothing on old iOS) and
 * those files were often rejected on upload or by ElevenLabs; WAV is accepted everywhere.
 */
export type WavRecording = { stop: () => Promise<Blob>; cancel: () => void; level: () => number };

const TARGET_RATE = 22050; // plenty for a voice clone, keeps 2 minutes at ~5 MB

export async function startWavRecording(): Promise<WavRecording> {
    const Ctx: typeof AudioContext = window.AudioContext || (window as any).webkitAudioContext;
    // Create the context inside the click (before any await) so Safari/iOS allows it to start.
    const ctx = new Ctx();
    let stream: MediaStream;
    try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, channelCount: 1 } });
    } catch (e) {
        ctx.close();
        throw e;
    }
    if (ctx.state === 'suspended') await ctx.resume();

    const source = ctx.createMediaStreamSource(stream);
    const analyser = ctx.createAnalyser();
    analyser.fftSize = 512;
    // ScriptProcessor is deprecated but works in every browser without a separate worklet file.
    const processor = ctx.createScriptProcessor(4096, 1, 1);
    const chunks: Float32Array[] = [];
    processor.onaudioprocess = (e) => chunks.push(new Float32Array(e.inputBuffer.getChannelData(0)));
    source.connect(analyser);
    source.connect(processor);
    // A muted sink keeps the processor running in Chrome without playing the mic back.
    const mute = ctx.createGain();
    mute.gain.value = 0;
    processor.connect(mute);
    mute.connect(ctx.destination);

    const buf = new Uint8Array(analyser.fftSize);
    const release = () => {
        processor.onaudioprocess = null;
        processor.disconnect();
        source.disconnect();
        stream.getTracks().forEach((t) => t.stop());
        ctx.close();
    };

    return {
        level: () => {
            analyser.getByteTimeDomainData(buf);
            let peak = 0;
            for (const v of buf) peak = Math.max(peak, Math.abs(v - 128));
            return peak / 128;
        },
        cancel: release,
        stop: async () => {
            const rate = ctx.sampleRate;
            release();
            return encodeWav(downsample(merge(chunks), rate, TARGET_RATE), Math.min(rate, TARGET_RATE));
        },
    };
}

function merge(chunks: Float32Array[]): Float32Array {
    const out = new Float32Array(chunks.reduce((n, c) => n + c.length, 0));
    let o = 0;
    for (const c of chunks) {
        out.set(c, o);
        o += c.length;
    }
    return out;
}

function downsample(data: Float32Array, from: number, to: number): Float32Array {
    if (to >= from) return data;
    const ratio = from / to;
    const out = new Float32Array(Math.floor(data.length / ratio));
    for (let i = 0; i < out.length; i++) {
        // average the source samples that fall into this output sample (simple low-pass)
        const start = Math.floor(i * ratio);
        const end = Math.min(data.length, Math.floor((i + 1) * ratio));
        let sum = 0;
        for (let j = start; j < end; j++) sum += data[j];
        out[i] = sum / Math.max(1, end - start);
    }
    return out;
}

function encodeWav(samples: Float32Array, rate: number): Blob {
    const view = new DataView(new ArrayBuffer(44 + samples.length * 2));
    const str = (o: number, s: string) => [...s].forEach((c, i) => view.setUint8(o + i, c.charCodeAt(0)));
    str(0, 'RIFF');
    view.setUint32(4, 36 + samples.length * 2, true);
    str(8, 'WAVE');
    str(12, 'fmt ');
    view.setUint32(16, 16, true);
    view.setUint16(20, 1, true); // PCM
    view.setUint16(22, 1, true); // mono
    view.setUint32(24, rate, true);
    view.setUint32(28, rate * 2, true);
    view.setUint16(32, 2, true);
    view.setUint16(34, 16, true);
    str(36, 'data');
    view.setUint32(40, samples.length * 2, true);
    let o = 44;
    for (const s of samples) {
        const c = Math.max(-1, Math.min(1, s));
        view.setInt16(o, c < 0 ? c * 0x8000 : c * 0x7fff, true);
        o += 2;
    }
    return new Blob([view], { type: 'audio/wav' });
}
