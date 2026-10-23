# Feature Refinement — Migrate Subtitle Speech-To-Text Provider (Groq → ElevenLabs Scribe)

## Objective

Replace the current Speech-to-Text provider used for subtitle generation from **Groq Whisper** to **ElevenLabs Speech-to-Text (Scribe)**.

This is **not a new feature**. This is a **provider migration / internal integration refactor**.

Existing subtitle generation behaviour must remain compatible unless explicitly stated.

---

# Scope

Current flow:

```
Video/Audio
    ↓
Speech-to-Text (Groq Whisper)
    ↓
Transcript
    ↓
Subtitle Builder
    ↓
Generated Subtitle
```

Target flow:

```
Video/Audio
    ↓
Speech-to-Text (ElevenLabs Scribe)
    ↓
Transcript
    ↓
Subtitle Builder
    ↓
Generated Subtitle
```

---

# Existing Provider (To Be Removed)

Current env:

```env
GROQ_API_KEY=
GROQ_ENDPOINT=https://api.groq.com/openai/v1
GROQ_WHISPER_MODEL=whisper-large-v3
```

Current assumptions:

* OpenAI-compatible request format may exist
* Existing response likely contains transcript segments/timestamps
* Existing implementation may rely on:

  * text
  * segments
  * timestamps
  * language
  * confidence
  * word-level metadata

---

# New Provider Configuration

New env:

```env
ELEVENLABS_API_KEY=
ELEVENLABS_STT_ENDPOINT=https://api.elevenlabs.io/v1/speech-to-text
ELEVENLABS_STT_MODEL=scribe_v1
```

Default model:

```
scribe_v1
```

---

# Required Investigation (MANDATORY)

Before implementing changes:

## 1. Locate Existing STT Integration

Identify:

* Service classes
* Clients
* DTOs
* Adapters
* Jobs
* Queues
* Actions
* Controllers
* Config files
* ENV bindings
* Subtitle builders
* Tests

Search keywords:

```txt
groq
whisper
speech
transcript
subtitle
transcribe
audio
caption
generate subtitle
```

Produce findings:

```md
Current Entry Point:
Current Request Flow:
Current Response Mapping:
Current Dependencies:
Risk Level:
```

---

## 2. Compatibility Audit

Compare current Groq response usage against ElevenLabs response.

Validate whether current downstream code depends on:

| Feature         | Current | ElevenLabs    | Action |
| --------------- | ------- | ------------- | ------ |
| Full transcript | ?       | text          | verify |
| Language        | ?       | language_code | map    |
| Segments        | ?       | words[]       | adapt  |
| Word timestamp  | ?       | start/end     | map    |
| Confidence      | ?       | logprob       | adapt  |
| Subtitle timing | ?       | derive        | verify |

If missing:

* preserve compatibility layer
* avoid breaking callers

---

# ElevenLabs Response Contract

Example response:

```json
{
  "language_code": "eng",
  "language_probability": 1,
  "text": "...",
  "words": [
    {
      "text": "hello",
      "start": 1.20,
      "end": 1.50,
      "type": "word",
      "logprob": -0.01
    }
  ]
}
```

Important notes:

### words[]

Contains:

* actual words
* spacing tokens
* audio events

Example:

```json
"type": "spacing"
```

```json
"type": "audio_event"
```

Implementation must verify whether subtitle generation should:

* include spacing
* ignore spacing
* ignore audio_event
* merge word tokens

---

# Required Refactor

## Introduce Provider Abstraction

If not already existing:

```text
SpeechToTextInterface
    ↓
GroqSpeechToTextProvider
ElevenLabsSpeechToTextProvider
```

Do not leak vendor-specific response into business layer.

---

## Introduce Internal DTO

Target normalized output:

```php
TranscriptResult
```

Example:

```php
class TranscriptResult
{
    string fullText;

    string language;

    array words;

    array subtitles;
}
```

Word DTO:

```php
class TranscriptWord
{
    string text;

    float start;

    float end;

    float confidence;
}
```

Map ElevenLabs response into this structure.

---

# Implementation Rules

Must:

* preserve existing subtitle output contract
* preserve queue/job behaviour
* preserve retry mechanism
* preserve timeout handling
* preserve observability/logging

Must NOT:

* change public API response
* break existing subtitle consumers
* expose ElevenLabs raw response downstream

---

# Failure Handling

Handle:

* empty transcript
* missing words[]
* unsupported language
* timeout
* 4xx
* 5xx
* invalid API key
* malformed response

Fallback behaviour must remain consistent.

---

# Config Refactor

Expected config:

```php
services.php
```

```php
'elevenlabs' => [
    'api_key' => env('ELEVENLABS_API_KEY'),
    'endpoint' => env('ELEVENLABS_STT_ENDPOINT'),
    'model' => env('ELEVENLABS_STT_MODEL'),
],
```

Remove dead Groq config if no longer used.

---

# Testing Requirements

Update:

### Unit Test

* response mapping
* transcript normalization
* word parsing

### Integration Test

* subtitle generation

### Regression Test

* generated subtitle remains compatible

### Failure Test

* timeout
* invalid response

---

# Deliverables

Return:

1. Files changed
2. Compatibility findings
3. Architecture decision
4. Migration summary
5. Risks
6. Test evidence
7. Example before/after request-response mapping
8. Dead code candidates for removal

# Full example response Eleven Labs API

{
  "language_code": "eng",
  "language_probability": 1,
  "text": "What is happening to me? Did you miss me, Spidey? I'm losing my mind. I am totally out of control. I gotta fix whatever this is right now. Final semester, MIT. Dad, who do you think is actually watching these? Uh, maybe someone. Okay. Well, bye, someone. What the ... Dr. Banner, you found a way to suppress mutating DNA, right? If you see me with this stuff, run. (laughs) So, could you get rid of the bad aspects, but keep the good? How would you decide what parts of nature are good or bad? Well, sorry about that. You're not one of my students, are you? It appears we're faced with a threat we can't control, one we can't even see. You're the only one who's immune to it. You're the only one who can sense it. What do you think they're looking for? Oh, I see you stumbled across my board. I'm not one of those weirdos, I swear. He's a little weird. I'm MJ. You two don't know each other? Yo, Spider-Man, you okay? You get rid of that monster inside you? I think I might go see how MJ's doing. You're not gonna get away with this. But he might. I didn't know you could get that big. Whoa, hey, hey, hey. I'm sorry, but you're in danger. You just have to trust me. Fine. This whole thing can get really scary. Who the hell is that? Who the hell are you? Frank, please, I don't have anywhere else to go. By the people who love you. Just gonna let him go by himself? Who love you because you're you. If you're gonna do something, you better do it now. And never forget that, no matter how powerful you become... I miss you, May. Huh. All right, just give me a sec. Give me a sec. I'm not in high school anymore. Oh, did you stretch? Are you a big stretcher? It's so good for you. You ready?",
  "words": [
    {
      "text": "What",
      "start": 3.64,
      "end": 3.8,
      "type": "word",
      "logprob": -0.589766800403595
    },
    {
      "text": " ",
      "start": 3.8,
      "end": 3.88,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "is",
      "start": 3.88,
      "end": 3.98,
      "type": "word",
      "logprob": 0
    },
    {
      "text": " ",
      "start": 3.98,
      "end": 4.04,
      "type": "spacing",
      "logprob": -0.000027179348762729205
    },
    {
      "text": "happening",
      "start": 4.04,
      "end": 4.5,
      "type": "word",
      "logprob": -0.000027179348762729205
    },
    {
      "text": " ",
      "start": 4.5,
      "end": 4.54,
      "type": "spacing",
      "logprob": -1.1920928244535389e-7
    },
    {
      "text": "to",
      "start": 4.54,
      "end": 4.62,
      "type": "word",
      "logprob": -1.1920928244535389e-7
    },
    {
      "text": " ",
      "start": 4.62,
      "end": 4.68,
      "type": "spacing",
      "logprob": -5.960462772236497e-7
    },
    {
      "text": "me?",
      "start": 4.68,
      "end": 4.88,
      "type": "word",
      "logprob": -0.0000023841797656132258
    },
    {
      "text": " ",
      "start": 4.88,
      "end": 5.42,
      "type": "spacing",
      "logprob": -0.0007215518853627145
    },
    {
      "text": "Did",
      "start": 5.42,
      "end": 5.5,
      "type": "word",
      "logprob": -0.0007215518853627145
    },
    {
      "text": " ",
      "start": 5.5,
      "end": 5.54,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "you",
      "start": 5.54,
      "end": 5.62,
      "type": "word",
      "logprob": 0
    },
    {
      "text": " ",
      "start": 5.62,
      "end": 5.64,
      "type": "spacing",
      "logprob": -0.0000290866428258596
    },
    {
      "text": "miss",
      "start": 5.64,
      "end": 5.8,
      "type": "word",
      "logprob": -0.0000290866428258596
    },
    {
      "text": " ",
      "start": 5.8,
      "end": 5.84,
      "type": "spacing",
      "logprob": -8.344646857949556e-7
    },
    {
      "text": "me,",
      "start": 5.84,
      "end": 5.9,
      "type": "word",
      "logprob": -0.000012635966186280712
    },
    {
      "text": " ",
      "start": 5.9,
      "end": 6.01,
      "type": "spacing",
      "logprob": -0.0005451146862469614
    },
    {
      "text": "Spidey?",
      "start": 6.01,
      "end": 6.34,
      "type": "word",
      "logprob": -0.0002473924736727489
    },
    {
      "text": " ",
      "start": 6.34,
      "end": 6.34,
      "type": "spacing",
      "logprob": -0.16976194083690643
    },
    {
      "text": "I'm",
      "start": 8.2,
      "end": 8.42,
      "type": "word",
      "logprob": -0.05664071723488936
    },
    {
      "text": " ",
      "start": 8.42,
      "end": 8.459,
      "type": "spacing",
      "logprob": -0.000006318072337307967
    },
    {
      "text": "losing",
      "start": 8.46,
      "end": 8.8,
      "type": "word",
      "logprob": -0.000006318072337307967
    },
    {
      "text": " ",
      "start": 8.8,
      "end": 8.82,
      "type": "spacing",
      "logprob": -0.0000011920922133867862
    },
    {
      "text": "my",
      "start": 8.82,
      "end": 8.94,
      "type": "word",
      "logprob": -0.0000011920922133867862
    },
    {
      "text": " ",
      "start": 8.94,
      "end": 9,
      "type": "spacing",
      "logprob": -0.000016212332411669195
    },
    {
      "text": "mind.",
      "start": 9,
      "end": 9.28,
      "type": "word",
      "logprob": -0.000014161955277813831
    },
    {
      "text": " ",
      "start": 9.28,
      "end": 9.28,
      "type": "spacing",
      "logprob": -0.006513792555779219
    },
    {
      "text": "I",
      "start": 11.9,
      "end": 11.92,
      "type": "word",
      "logprob": -0.006513792555779219
    },
    {
      "text": " ",
      "start": 11.92,
      "end": 11.94,
      "type": "spacing",
      "logprob": -0.000002622600959512056
    },
    {
      "text": "am",
      "start": 11.94,
      "end": 12.26,
      "type": "word",
      "logprob": -0.000002622600959512056
    },
    {
      "text": " ",
      "start": 12.26,
      "end": 12.32,
      "type": "spacing",
      "logprob": -0.0008775911992415786
    },
    {
      "text": "totally",
      "start": 12.32,
      "end": 12.68,
      "type": "word",
      "logprob": -0.0008775911992415786
    },
    {
      "text": " ",
      "start": 12.68,
      "end": 12.74,
      "type": "spacing",
      "logprob": -4.768370445162873e-7
    },
    {
      "text": "out",
      "start": 12.74,
      "end": 12.8,
      "type": "word",
      "logprob": -4.768370445162873e-7
    },
    {
      "text": " ",
      "start": 12.8,
      "end": 12.82,
      "type": "spacing",
      "logprob": -1.1920928244535389e-7
    },
    {
      "text": "of",
      "start": 12.82,
      "end": 12.89,
      "type": "word",
      "logprob": -1.1920928244535389e-7
    },
    {
      "text": " ",
      "start": 12.89,
      "end": 12.9,
      "type": "spacing",
      "logprob": -0.000030397906812140718
    },
    {
      "text": "control.",
      "start": 12.9,
      "end": 13.26,
      "type": "word",
      "logprob": -0.000026672674245276085
    },
    {
      "text": " ",
      "start": 13.26,
      "end": 13.26,
      "type": "spacing",
      "logprob": -0.00011681827891152352
    },
    {
      "text": "I",
      "start": 15.14,
      "end": 15.22,
      "type": "word",
      "logprob": -0.00011681827891152352
    },
    {
      "text": " ",
      "start": 15.22,
      "end": 15.26,
      "type": "spacing",
      "logprob": -0.03833022341132164
    },
    {
      "text": "gotta",
      "start": 15.26,
      "end": 15.46,
      "type": "word",
      "logprob": -0.03833022341132164
    },
    {
      "text": " ",
      "start": 15.46,
      "end": 15.52,
      "type": "spacing",
      "logprob": -0.00007450303382938728
    },
    {
      "text": "fix",
      "start": 15.52,
      "end": 15.7,
      "type": "word",
      "logprob": -0.00007450303382938728
    },
    {
      "text": " ",
      "start": 15.7,
      "end": 15.78,
      "type": "spacing",
      "logprob": -0.00004815939246327616
    },
    {
      "text": "whatever",
      "start": 15.78,
      "end": 16.12,
      "type": "word",
      "logprob": -0.00004815939246327616
    },
    {
      "text": " ",
      "start": 16.12,
      "end": 16.13,
      "type": "spacing",
      "logprob": -3.576278118089249e-7
    },
    {
      "text": "this",
      "start": 16.13,
      "end": 16.31,
      "type": "word",
      "logprob": -3.576278118089249e-7
    },
    {
      "text": " ",
      "start": 16.31,
      "end": 16.379,
      "type": "spacing",
      "logprob": -0.0000011920922133867862
    },
    {
      "text": "is",
      "start": 16.379,
      "end": 16.57,
      "type": "word",
      "logprob": -0.0000011920922133867862
    },
    {
      "text": " ",
      "start": 16.57,
      "end": 16.599,
      "type": "spacing",
      "logprob": -0.0002631794777698815
    },
    {
      "text": "right",
      "start": 16.6,
      "end": 16.84,
      "type": "word",
      "logprob": -0.0002631794777698815
    },
    {
      "text": " ",
      "start": 16.84,
      "end": 16.88,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "now.",
      "start": 16.88,
      "end": 17.98,
      "type": "word",
      "logprob": -2.3841846541472478e-7
    },
    {
      "text": " ",
      "start": 17.98,
      "end": 18.08,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "Final",
      "start": 18.08,
      "end": 18.39,
      "type": "word",
      "logprob": -0.004242349416017532
    },
    {
      "text": " ",
      "start": 18.39,
      "end": 18.439,
      "type": "spacing",
      "logprob": -0.0000033378546504536644
    },
    {
      "text": "semester,",
      "start": 18.44,
      "end": 19.08,
      "type": "word",
      "logprob": -0.001409870774498106
    },
    {
      "text": " ",
      "start": 19.08,
      "end": 19.26,
      "type": "spacing",
      "logprob": -0.000036000557884108275
    },
    {
      "text": "MIT.",
      "start": 19.26,
      "end": 20.48,
      "type": "word",
      "logprob": -0.000032543588986300165
    },
    {
      "text": " ",
      "start": 20.48,
      "end": 20.52,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "Dad,",
      "start": 20.52,
      "end": 20.72,
      "type": "word",
      "logprob": -0.0011769183565775165
    },
    {
      "text": " ",
      "start": 20.72,
      "end": 20.94,
      "type": "spacing",
      "logprob": -0.0000017881377516459906
    },
    {
      "text": "who",
      "start": 20.94,
      "end": 21.02,
      "type": "word",
      "logprob": -0.0000017881377516459906
    },
    {
      "text": " ",
      "start": 21.02,
      "end": 21.04,
      "type": "spacing",
      "logprob": -1.1920928244535389e-7
    },
    {
      "text": "do",
      "start": 21.04,
      "end": 21.08,
      "type": "word",
      "logprob": -1.1920928244535389e-7
    },
    {
      "text": " ",
      "start": 21.08,
      "end": 21.12,
      "type": "spacing",
      "logprob": -0.000001311301275563892
    },
    {
      "text": "you",
      "start": 21.12,
      "end": 21.16,
      "type": "word",
      "logprob": -0.000001311301275563892
    },
    {
      "text": " ",
      "start": 21.16,
      "end": 21.2,
      "type": "spacing",
      "logprob": -0.00011085849109804258
    },
    {
      "text": "think",
      "start": 21.2,
      "end": 21.32,
      "type": "word",
      "logprob": -0.00011085849109804258
    },
    {
      "text": " ",
      "start": 21.32,
      "end": 21.36,
      "type": "spacing",
      "logprob": -1.1920928244535389e-7
    },
    {
      "text": "is",
      "start": 21.36,
      "end": 21.42,
      "type": "word",
      "logprob": -1.1920928244535389e-7
    },
    {
      "text": " ",
      "start": 21.42,
      "end": 21.44,
      "type": "spacing",
      "logprob": -0.00001490105023549404
    },
    {
      "text": "actually",
      "start": 21.44,
      "end": 21.72,
      "type": "word",
      "logprob": -0.00001490105023549404
    },
    {
      "text": " ",
      "start": 21.72,
      "end": 21.759,
      "type": "spacing",
      "logprob": -0.000058053239627042785
    },
    {
      "text": "watching",
      "start": 21.76,
      "end": 22.06,
      "type": "word",
      "logprob": -0.000058053239627042785
    },
    {
      "text": " ",
      "start": 22.06,
      "end": 22.099,
      "type": "spacing",
      "logprob": -0.0002964295563288033
    },
    {
      "text": "these?",
      "start": 22.1,
      "end": 22.28,
      "type": "word",
      "logprob": -0.00024960747820538626
    },
    {
      "text": " ",
      "start": 22.28,
      "end": 22.52,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "Uh,",
      "start": 22.52,
      "end": 22.64,
      "type": "word",
      "logprob": -0.0012991506726696873
    },
    {
      "text": " ",
      "start": 22.64,
      "end": 22.76,
      "type": "spacing",
      "logprob": -0.000005483612312673358
    },
    {
      "text": "maybe",
      "start": 22.76,
      "end": 22.98,
      "type": "word",
      "logprob": -0.000005483612312673358
    },
    {
      "text": " ",
      "start": 22.98,
      "end": 23.04,
      "type": "spacing",
      "logprob": -0.0000020265558760002023
    },
    {
      "text": "someone.",
      "start": 23.04,
      "end": 23.38,
      "type": "word",
      "logprob": -0.000001773236391500177
    },
    {
      "text": " ",
      "start": 23.38,
      "end": 23.44,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "Okay.",
      "start": 23.44,
      "end": 23.6,
      "type": "word",
      "logprob": -0.00011293246789136901
    },
    {
      "text": " ",
      "start": 23.6,
      "end": 23.7,
      "type": "spacing",
      "logprob": -0.00001549708758830093
    },
    {
      "text": "Well,",
      "start": 23.7,
      "end": 23.81,
      "type": "word",
      "logprob": -0.000012421511927129814
    },
    {
      "text": " ",
      "start": 23.81,
      "end": 23.88,
      "type": "spacing",
      "logprob": -0.0002882065309677273
    },
    {
      "text": "bye,",
      "start": 23.88,
      "end": 24,
      "type": "word",
      "logprob": -0.0002470579966029618
    },
    {
      "text": " ",
      "start": 24,
      "end": 24.08,
      "type": "spacing",
      "logprob": -0.0000016689286894688848
    },
    {
      "text": "someone.",
      "start": 24.08,
      "end": 24.66,
      "type": "word",
      "logprob": -0.0000014603126032852742
    },
    {
      "text": " ",
      "start": 24.66,
      "end": 24.66,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "What",
      "start": 29.76,
      "end": 29.96,
      "type": "word",
      "logprob": -0.000023841574147809297
    },
    {
      "text": " ",
      "start": 29.96,
      "end": 30.02,
      "type": "spacing",
      "logprob": -5.960462772236497e-7
    },
    {
      "text": "the",
      "start": 30.02,
      "end": 30.22,
      "type": "word",
      "logprob": -5.960462772236497e-7
    },
    {
      "text": " ",
      "start": 30.22,
      "end": 30.3,
      "type": "spacing",
      "logprob": -1.1972012519836426
    },
    {
      "text": "...",
      "start": 30.3,
      "end": 30.3,
      "type": "word",
      "logprob": -1.1972012519836426
    },
    {
      "text": " ",
      "start": 30.3,
      "end": 30.62,
      "type": "spacing",
      "logprob": -0.5371623635292053
    },
    {
      "text": "Dr.",
      "start": 30.62,
      "end": 30.73,
      "type": "word",
      "logprob": -0.358108441034896
    },
    {
      "text": " ",
      "start": 30.73,
      "end": 30.92,
      "type": "spacing",
      "logprob": -0.002063171239569783
    },
    {
      "text": "Banner,",
      "start": 30.92,
      "end": 31.12,
      "type": "word",
      "logprob": -0.0019358404512916292
    },
    {
      "text": " ",
      "start": 31.12,
      "end": 31.419,
      "type": "spacing",
      "logprob": -2.3841855067985307e-7
    },
    {
      "text": "you",
      "start": 31.42,
      "end": 31.48,
      "type": "word",
      "logprob": -2.3841855067985307e-7
    },
    {
      "text": " ",
      "start": 31.48,
      "end": 31.56,
      "type": "spacing",
      "logprob": -0.002216027583926916
    },
    {
      "text": "found",
      "start": 31.56,
      "end": 31.79,
      "type": "word",
      "logprob": -0.002216027583926916
    },
    {
      "text": " ",
      "start": 31.79,
      "end": 31.84,
      "type": "spacing",
      "logprob": -9.536738616588991e-7
    },
    {
      "text": "a",
      "start": 31.84,
      "end": 31.88,
      "type": "word",
      "logprob": -9.536738616588991e-7
    },
    {
      "text": " ",
      "start": 31.88,
      "end": 31.919,
      "type": "spacing",
      "logprob": -0.0000609140915912576
    },
    {
      "text": "way",
      "start": 31.92,
      "end": 32.04,
      "type": "word",
      "logprob": -0.0000609140915912576
    },
    {
      "text": " ",
      "start": 32.04,
      "end": 32.08,
      "type": "spacing",
      "logprob": -0.000011801649634435307
    },
    {
      "text": "to",
      "start": 32.08,
      "end": 32.18,
      "type": "word",
      "logprob": -0.000011801649634435307
    },
    {
      "text": " ",
      "start": 32.18,
      "end": 32.2,
      "type": "spacing",
      "logprob": -0.00016783259343355894
    },
    {
      "text": "suppress",
      "start": 32.2,
      "end": 32.54,
      "type": "word",
      "logprob": -0.00016783259343355894
    },
    {
      "text": " ",
      "start": 32.54,
      "end": 32.619,
      "type": "spacing",
      "logprob": -0.000011444026313256472
    },
    {
      "text": "mutating",
      "start": 32.619,
      "end": 33.22,
      "type": "word",
      "logprob": -0.000011444026313256472
    },
    {
      "text": " ",
      "start": 33.22,
      "end": 33.26,
      "type": "spacing",
      "logprob": -0.00006568216485902667
    },
    {
      "text": "DNA,",
      "start": 33.26,
      "end": 33.62,
      "type": "word",
      "logprob": -0.00004926162364427
    },
    {
      "text": " ",
      "start": 33.62,
      "end": 33.74,
      "type": "spacing",
      "logprob": -0.000010847986231965479
    },
    {
      "text": "right?",
      "start": 33.74,
      "end": 33.92,
      "type": "word",
      "logprob": -0.00001058970201484044
    },
    {
      "text": " ",
      "start": 33.92,
      "end": 34.12,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "If",
      "start": 34.12,
      "end": 34.22,
      "type": "word",
      "logprob": -0.000058410845667822286
    },
    {
      "text": " ",
      "start": 34.22,
      "end": 34.26,
      "type": "spacing",
      "logprob": -0.00006711257447022945
    },
    {
      "text": "you",
      "start": 34.26,
      "end": 34.32,
      "type": "word",
      "logprob": -0.00006711257447022945
    },
    {
      "text": " ",
      "start": 34.32,
      "end": 34.38,
      "type": "spacing",
      "logprob": -0.012097911909222603
    },
    {
      "text": "see",
      "start": 34.38,
      "end": 34.56,
      "type": "word",
      "logprob": -0.012097911909222603
    },
    {
      "text": " ",
      "start": 34.56,
      "end": 34.58,
      "type": "spacing",
      "logprob": -0.10194166749715805
    },
    {
      "text": "me",
      "start": 34.58,
      "end": 34.629,
      "type": "word",
      "logprob": -0.10194166749715805
    },
    {
      "text": " ",
      "start": 34.629,
      "end": 34.68,
      "type": "spacing",
      "logprob": -0.0000016689286894688848
    },
    {
      "text": "with",
      "start": 34.68,
      "end": 34.76,
      "type": "word",
      "logprob": -0.0000016689286894688848
    },
    {
      "text": " ",
      "start": 34.76,
      "end": 34.8,
      "type": "spacing",
      "logprob": -0.0002743821241892874
    },
    {
      "text": "this",
      "start": 34.8,
      "end": 34.92,
      "type": "word",
      "logprob": -0.0002743821241892874
    },
    {
      "text": " ",
      "start": 34.92,
      "end": 34.94,
      "type": "spacing",
      "logprob": -0.24830655753612518
    },
    {
      "text": "stuff,",
      "start": 34.94,
      "end": 35.18,
      "type": "word",
      "logprob": -0.20692217101652943
    },
    {
      "text": " ",
      "start": 35.18,
      "end": 35.64,
      "type": "spacing",
      "logprob": -0.000001311301275563892
    },
    {
      "text": "run.",
      "start": 35.64,
      "end": 35.88,
      "type": "word",
      "logprob": -0.0000010430805943428823
    },
    {
      "text": " ",
      "start": 35.88,
      "end": 35.88,
      "type": "spacing",
      "logprob": -0.18173183500766754
    },
    {
      "text": "(laughs)",
      "start": 37.6,
      "end": 37.78,
      "type": "audio_event",
      "logprob": -0.06727284316518005
    },
    {
      "text": " ",
      "start": 37.78,
      "end": 37.78,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "So,",
      "start": 39.08,
      "end": 39.2,
      "type": "word",
      "logprob": -0.006793205820334454
    },
    {
      "text": " ",
      "start": 39.2,
      "end": 39.26,
      "type": "spacing",
      "logprob": -0.00001585470999998506
    },
    {
      "text": "could",
      "start": 39.26,
      "end": 39.43,
      "type": "word",
      "logprob": -0.00001585470999998506
    },
    {
      "text": " ",
      "start": 39.43,
      "end": 39.52,
      "type": "spacing",
      "logprob": -0.0006420934805646539
    },
    {
      "text": "you",
      "start": 39.52,
      "end": 39.66,
      "type": "word",
      "logprob": -0.0006420934805646539
    },
    {
      "text": " ",
      "start": 39.66,
      "end": 39.7,
      "type": "spacing",
      "logprob": -0.000059602869441732764
    },
    {
      "text": "get",
      "start": 39.7,
      "end": 39.78,
      "type": "word",
      "logprob": -0.000059602869441732764
    },
    {
      "text": " ",
      "start": 39.78,
      "end": 39.85,
      "type": "spacing",
      "logprob": -0.000010251946150674485
    },
    {
      "text": "rid",
      "start": 39.85,
      "end": 39.9,
      "type": "word",
      "logprob": -0.000010251946150674485
    },
    {
      "text": " ",
      "start": 39.9,
      "end": 39.94,
      "type": "spacing",
      "logprob": -3.576278118089249e-7
    },
    {
      "text": "of",
      "start": 39.94,
      "end": 39.98,
      "type": "word",
      "logprob": -3.576278118089249e-7
    },
    {
      "text": " ",
      "start": 39.98,
      "end": 40.02,
      "type": "spacing",
      "logprob": -4.768370445162873e-7
    },
    {
      "text": "the",
      "start": 40.02,
      "end": 40.07,
      "type": "word",
      "logprob": -4.768370445162873e-7
    },
    {
      "text": " ",
      "start": 40.07,
      "end": 40.14,
      "type": "spacing",
      "logprob": -0.000006794906312279636
    },
    {
      "text": "bad",
      "start": 40.14,
      "end": 40.32,
      "type": "word",
      "logprob": -0.000006794906312279636
    },
    {
      "text": " ",
      "start": 40.32,
      "end": 40.36,
      "type": "spacing",
      "logprob": -0.0011554239317774773
    },
    {
      "text": "aspects,",
      "start": 40.36,
      "end": 40.73,
      "type": "word",
      "logprob": -0.0011583262676140293
    },
    {
      "text": " ",
      "start": 40.73,
      "end": 40.78,
      "type": "spacing",
      "logprob": -0.0000032186455882765586
    },
    {
      "text": "but",
      "start": 40.78,
      "end": 40.88,
      "type": "word",
      "logprob": -0.0000032186455882765586
    },
    {
      "text": " ",
      "start": 40.88,
      "end": 40.92,
      "type": "spacing",
      "logprob": -0.000026940935640595853
    },
    {
      "text": "keep",
      "start": 40.92,
      "end": 41.08,
      "type": "word",
      "logprob": -0.000026940935640595853
    },
    {
      "text": " ",
      "start": 41.08,
      "end": 41.1,
      "type": "spacing",
      "logprob": -0.000003933898824470816
    },
    {
      "text": "the",
      "start": 41.1,
      "end": 41.16,
      "type": "word",
      "logprob": -0.000003933898824470816
    },
    {
      "text": " ",
      "start": 41.16,
      "end": 41.24,
      "type": "spacing",
      "logprob": -0.000013589766240329482
    },
    {
      "text": "good?",
      "start": 41.24,
      "end": 41.42,
      "type": "word",
      "logprob": -0.000011110231434940943
    },
    {
      "text": " ",
      "start": 41.42,
      "end": 41.9,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "How",
      "start": 41.9,
      "end": 41.98,
      "type": "word",
      "logprob": -0.00022968991834204644
    },
    {
      "text": " ",
      "start": 41.98,
      "end": 42.02,
      "type": "spacing",
      "logprob": -0.0028347091283649206
    },
    {
      "text": "would",
      "start": 42.02,
      "end": 42.14,
      "type": "word",
      "logprob": -0.0028347091283649206
    },
    {
      "text": " ",
      "start": 42.14,
      "end": 42.18,
      "type": "spacing",
      "logprob": -0.000007629365427419543
    },
    {
      "text": "you",
      "start": 42.18,
      "end": 42.26,
      "type": "word",
      "logprob": -0.000007629365427419543
    },
    {
      "text": " ",
      "start": 42.26,
      "end": 42.3,
      "type": "spacing",
      "logprob": -0.000045060096454108134
    },
    {
      "text": "decide",
      "start": 42.3,
      "end": 42.82,
      "type": "word",
      "logprob": -0.000045060096454108134
    },
    {
      "text": " ",
      "start": 42.82,
      "end": 43.22,
      "type": "spacing",
      "logprob": -0.11291009187698364
    },
    {
      "text": "what",
      "start": 43.22,
      "end": 43.34,
      "type": "word",
      "logprob": -0.11291009187698364
    },
    {
      "text": " ",
      "start": 43.34,
      "end": 43.44,
      "type": "spacing",
      "logprob": -0.000016569954823353328
    },
    {
      "text": "parts",
      "start": 43.44,
      "end": 43.66,
      "type": "word",
      "logprob": -0.000016569954823353328
    },
    {
      "text": " ",
      "start": 43.66,
      "end": 43.68,
      "type": "spacing",
      "logprob": -0.0000029802276912960224
    },
    {
      "text": "of",
      "start": 43.68,
      "end": 43.76,
      "type": "word",
      "logprob": -0.0000029802276912960224
    },
    {
      "text": " ",
      "start": 43.76,
      "end": 43.82,
      "type": "spacing",
      "logprob": -0.000047444173105759546
    },
    {
      "text": "nature",
      "start": 43.82,
      "end": 44.14,
      "type": "word",
      "logprob": -0.000047444173105759546
    },
    {
      "text": " ",
      "start": 44.14,
      "end": 44.18,
      "type": "spacing",
      "logprob": -5.960462772236497e-7
    },
    {
      "text": "are",
      "start": 44.18,
      "end": 44.3,
      "type": "word",
      "logprob": -5.960462772236497e-7
    },
    {
      "text": " ",
      "start": 44.3,
      "end": 44.36,
      "type": "spacing",
      "logprob": -4.768370445162873e-7
    },
    {
      "text": "good",
      "start": 44.36,
      "end": 44.48,
      "type": "word",
      "logprob": -4.768370445162873e-7
    },
    {
      "text": " ",
      "start": 44.48,
      "end": 44.5,
      "type": "spacing",
      "logprob": -2.3841855067985307e-7
    },
    {
      "text": "or",
      "start": 44.5,
      "end": 44.55,
      "type": "word",
      "logprob": -2.3841855067985307e-7
    },
    {
      "text": " ",
      "start": 44.55,
      "end": 44.62,
      "type": "spacing",
      "logprob": -5.960462772236497e-7
    },
    {
      "text": "bad?",
      "start": 44.62,
      "end": 45,
      "type": "word",
      "logprob": -0.0000015497182488388717
    },
    {
      "text": " ",
      "start": 45,
      "end": 45,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "Well,",
      "start": 46.66,
      "end": 46.84,
      "type": "word",
      "logprob": -0.4271200180053711
    },
    {
      "text": " ",
      "start": 46.84,
      "end": 47.44,
      "type": "spacing",
      "logprob": -0.00012206286191940308
    },
    {
      "text": "sorry",
      "start": 47.44,
      "end": 47.58,
      "type": "word",
      "logprob": -0.00012206286191940308
    },
    {
      "text": " ",
      "start": 47.58,
      "end": 47.6,
      "type": "spacing",
      "logprob": -0.0000016689286894688848
    },
    {
      "text": "about",
      "start": 47.6,
      "end": 47.73,
      "type": "word",
      "logprob": -0.0000016689286894688848
    },
    {
      "text": " ",
      "start": 47.73,
      "end": 47.8,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "that.",
      "start": 47.8,
      "end": 48,
      "type": "word",
      "logprob": 0
    },
    {
      "text": " ",
      "start": 48,
      "end": 48.46,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "You're",
      "start": 48.46,
      "end": 48.58,
      "type": "word",
      "logprob": -0.00008904299647838343
    },
    {
      "text": " ",
      "start": 48.58,
      "end": 48.62,
      "type": "spacing",
      "logprob": -3.576278118089249e-7
    },
    {
      "text": "not",
      "start": 48.62,
      "end": 48.74,
      "type": "word",
      "logprob": -3.576278118089249e-7
    },
    {
      "text": " ",
      "start": 48.74,
      "end": 48.78,
      "type": "spacing",
      "logprob": -0.000026464111215318553
    },
    {
      "text": "one",
      "start": 48.78,
      "end": 48.97,
      "type": "word",
      "logprob": -0.000026464111215318553
    },
    {
      "text": " ",
      "start": 48.97,
      "end": 48.98,
      "type": "spacing",
      "logprob": -2.3841855067985307e-7
    },
    {
      "text": "of",
      "start": 48.98,
      "end": 48.99,
      "type": "word",
      "logprob": -2.3841855067985307e-7
    },
    {
      "text": " ",
      "start": 48.99,
      "end": 48.99,
      "type": "spacing",
      "logprob": -0.000010847986231965479
    },
    {
      "text": "my",
      "start": 48.99,
      "end": 49.12,
      "type": "word",
      "logprob": -0.000010847986231965479
    },
    {
      "text": " ",
      "start": 49.12,
      "end": 49.13,
      "type": "spacing",
      "logprob": -0.00009858122211880982
    },
    {
      "text": "students,",
      "start": 49.13,
      "end": 49.5,
      "type": "word",
      "logprob": -0.00008762775299449761
    },
    {
      "text": " ",
      "start": 49.5,
      "end": 49.56,
      "type": "spacing",
      "logprob": -0.0000010728830375228426
    },
    {
      "text": "are",
      "start": 49.56,
      "end": 49.7,
      "type": "word",
      "logprob": -0.0000010728830375228426
    },
    {
      "text": " ",
      "start": 49.7,
      "end": 49.74,
      "type": "spacing",
      "logprob": -0.00002825220326485578
    },
    {
      "text": "you?",
      "start": 49.74,
      "end": 49.84,
      "type": "word",
      "logprob": -0.000021338164017947747
    },
    {
      "text": " ",
      "start": 49.84,
      "end": 49.84,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "It",
      "start": 52.4,
      "end": 52.48,
      "type": "word",
      "logprob": -0.00010787858627736568
    },
    {
      "text": " ",
      "start": 52.48,
      "end": 52.52,
      "type": "spacing",
      "logprob": -0.000011444026313256472
    },
    {
      "text": "appears",
      "start": 52.52,
      "end": 52.76,
      "type": "word",
      "logprob": -0.000011444026313256472
    },
    {
      "text": " ",
      "start": 52.76,
      "end": 52.78,
      "type": "spacing",
      "logprob": -0.00009715084888739511
    },
    {
      "text": "we're",
      "start": 52.78,
      "end": 52.9,
      "type": "word",
      "logprob": -0.00006561030822922475
    },
    {
      "text": " ",
      "start": 52.9,
      "end": 52.94,
      "type": "spacing",
      "logprob": -0.000041483970562694594
    },
    {
      "text": "faced",
      "start": 52.94,
      "end": 53.14,
      "type": "word",
      "logprob": -0.000041483970562694594
    },
    {
      "text": " ",
      "start": 53.14,
      "end": 53.18,
      "type": "spacing",
      "logprob": -7.152555099310121e-7
    },
    {
      "text": "with",
      "start": 53.18,
      "end": 53.3,
      "type": "word",
      "logprob": -7.152555099310121e-7
    },
    {
      "text": " ",
      "start": 53.3,
      "end": 53.36,
      "type": "spacing",
      "logprob": -0.0000015497195136049413
    },
    {
      "text": "a",
      "start": 53.36,
      "end": 53.44,
      "type": "word",
      "logprob": -0.0000015497195136049413
    },
    {
      "text": " ",
      "start": 53.44,
      "end": 53.46,
      "type": "spacing",
      "logprob": -0.0003355178632773459
    },
    {
      "text": "threat",
      "start": 53.46,
      "end": 53.74,
      "type": "word",
      "logprob": -0.0003355178632773459
    },
    {
      "text": " ",
      "start": 53.74,
      "end": 53.82,
      "type": "spacing",
      "logprob": -8.344646857949556e-7
    },
    {
      "text": "we",
      "start": 53.82,
      "end": 53.96,
      "type": "word",
      "logprob": -8.344646857949556e-7
    },
    {
      "text": " ",
      "start": 53.96,
      "end": 54,
      "type": "spacing",
      "logprob": -0.0000010728830375228426
    },
    {
      "text": "can't",
      "start": 54,
      "end": 54.18,
      "type": "word",
      "logprob": -0.000024103429700517155
    },
    {
      "text": " ",
      "start": 54.18,
      "end": 54.22,
      "type": "spacing",
      "logprob": -0.000007867782187531702
    },
    {
      "text": "control,",
      "start": 54.22,
      "end": 54.64,
      "type": "word",
      "logprob": -0.00028008147114633175
    },
    {
      "text": " ",
      "start": 54.64,
      "end": 55.4,
      "type": "spacing",
      "logprob": -0.0000084638240878121
    },
    {
      "text": "one",
      "start": 55.4,
      "end": 55.52,
      "type": "word",
      "logprob": -0.0000084638240878121
    },
    {
      "text": " ",
      "start": 55.52,
      "end": 55.58,
      "type": "spacing",
      "logprob": -0.0000016689286894688848
    },
    {
      "text": "we",
      "start": 55.58,
      "end": 55.66,
      "type": "word",
      "logprob": -0.0000016689286894688848
    },
    {
      "text": " ",
      "start": 55.66,
      "end": 55.72,
      "type": "spacing",
      "logprob": -0.0000027418097943154862
    },
    {
      "text": "can't",
      "start": 55.72,
      "end": 55.86,
      "type": "word",
      "logprob": -0.000011610858064159401
    },
    {
      "text": " ",
      "start": 55.86,
      "end": 55.9,
      "type": "spacing",
      "logprob": -5.960462772236497e-7
    },
    {
      "text": "even",
      "start": 55.9,
      "end": 56.1,
      "type": "word",
      "logprob": -5.960462772236497e-7
    },
    {
      "text": " ",
      "start": 56.1,
      "end": 56.16,
      "type": "spacing",
      "logprob": -0.0000034570634852570947
    },
    {
      "text": "see.",
      "start": 56.16,
      "end": 57.16,
      "type": "word",
      "logprob": -0.000002592797613942821
    },
    {
      "text": " ",
      "start": 57.16,
      "end": 57.16,
      "type": "spacing",
      "logprob": -0.000002145764938177308
    },
    {
      "text": "You're",
      "start": 61.28,
      "end": 61.36,
      "type": "word",
      "logprob": -0.0000016093239878500754
    },
    {
      "text": " ",
      "start": 61.36,
      "end": 61.38,
      "type": "spacing",
      "logprob": -0.00001156323378381785
    },
    {
      "text": "the",
      "start": 61.38,
      "end": 61.46,
      "type": "word",
      "logprob": -0.00001156323378381785
    },
    {
      "text": " ",
      "start": 61.46,
      "end": 61.5,
      "type": "spacing",
      "logprob": -2.3841855067985307e-7
    },
    {
      "text": "only",
      "start": 61.5,
      "end": 61.62,
      "type": "word",
      "logprob": -2.3841855067985307e-7
    },
    {
      "text": " ",
      "start": 61.62,
      "end": 61.64,
      "type": "spacing",
      "logprob": -0.00011789103882620111
    },
    {
      "text": "one",
      "start": 61.64,
      "end": 61.72,
      "type": "word",
      "logprob": -0.00011789103882620111
    },
    {
      "text": " ",
      "start": 61.72,
      "end": 61.74,
      "type": "spacing",
      "logprob": -0.000006794906312279636
    },
    {
      "text": "who's",
      "start": 61.74,
      "end": 61.86,
      "type": "word",
      "logprob": -0.000004315362298257242
    },
    {
      "text": " ",
      "start": 61.86,
      "end": 61.9,
      "type": "spacing",
      "logprob": -0.00004005352093372494
    },
    {
      "text": "immune",
      "start": 61.9,
      "end": 62.2,
      "type": "word",
      "logprob": -0.00004005352093372494
    },
    {
      "text": " ",
      "start": 62.2,
      "end": 62.26,
      "type": "spacing",
      "logprob": -8.344646857949556e-7
    },
    {
      "text": "to",
      "start": 62.26,
      "end": 62.37,
      "type": "word",
      "logprob": -8.344646857949556e-7
    },
    {
      "text": " ",
      "start": 62.37,
      "end": 62.44,
      "type": "spacing",
      "logprob": -1.1920928244535389e-7
    },
    {
      "text": "it.",
      "start": 62.44,
      "end": 62.56,
      "type": "word",
      "logprob": -0.000002821275297530216
    },
    {
      "text": " ",
      "start": 62.56,
      "end": 62.56,
      "type": "spacing",
      "logprob": -0.0000040531076592742465
    },
    {
      "text": "You're",
      "start": 63.9,
      "end": 64,
      "type": "word",
      "logprob": -0.000015377815998363076
    },
    {
      "text": " ",
      "start": 64,
      "end": 64.04,
      "type": "spacing",
      "logprob": -7.152555099310121e-7
    },
    {
      "text": "the",
      "start": 64.04,
      "end": 64.08,
      "type": "word",
      "logprob": -7.152555099310121e-7
    },
    {
      "text": " ",
      "start": 64.08,
      "end": 64.12,
      "type": "spacing",
      "logprob": -4.768370445162873e-7
    },
    {
      "text": "only",
      "start": 64.12,
      "end": 64.239,
      "type": "word",
      "logprob": -4.768370445162873e-7
    },
    {
      "text": " ",
      "start": 64.239,
      "end": 64.28,
      "type": "spacing",
      "logprob": -0.000006794906312279636
    },
    {
      "text": "one",
      "start": 64.28,
      "end": 64.36,
      "type": "word",
      "logprob": -0.000006794906312279636
    },
    {
      "text": " ",
      "start": 64.36,
      "end": 64.4,
      "type": "spacing",
      "logprob": -0.00002038458114839159
    },
    {
      "text": "who",
      "start": 64.4,
      "end": 64.48,
      "type": "word",
      "logprob": -0.00002038458114839159
    },
    {
      "text": " ",
      "start": 64.48,
      "end": 64.519,
      "type": "spacing",
      "logprob": -0.00003731181277544238
    },
    {
      "text": "can",
      "start": 64.519,
      "end": 64.68,
      "type": "word",
      "logprob": -0.00003731181277544238
    },
    {
      "text": " ",
      "start": 64.68,
      "end": 64.72,
      "type": "spacing",
      "logprob": -0.000024676019165781327
    },
    {
      "text": "sense",
      "start": 64.72,
      "end": 64.96,
      "type": "word",
      "logprob": -0.000024676019165781327
    },
    {
      "text": " ",
      "start": 64.96,
      "end": 65,
      "type": "spacing",
      "logprob": -1.1920928244535389e-7
    },
    {
      "text": "it.",
      "start": 65,
      "end": 65.32,
      "type": "word",
      "logprob": -1.5894570519018694e-7
    },
    {
      "text": " ",
      "start": 65.32,
      "end": 65.32,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "What",
      "start": 67.84,
      "end": 68,
      "type": "word",
      "logprob": -0.015864580869674683
    },
    {
      "text": " ",
      "start": 68,
      "end": 68.02,
      "type": "spacing",
      "logprob": -2.3841855067985307e-7
    },
    {
      "text": "do",
      "start": 68.02,
      "end": 68.04,
      "type": "word",
      "logprob": -2.3841855067985307e-7
    },
    {
      "text": " ",
      "start": 68.04,
      "end": 68.08,
      "type": "spacing",
      "logprob": -0.0000015497195136049413
    },
    {
      "text": "you",
      "start": 68.08,
      "end": 68.12,
      "type": "word",
      "logprob": -0.0000015497195136049413
    },
    {
      "text": " ",
      "start": 68.12,
      "end": 68.16,
      "type": "spacing",
      "logprob": -0.0000010728830375228426
    },
    {
      "text": "think",
      "start": 68.16,
      "end": 68.3,
      "type": "word",
      "logprob": -0.0000010728830375228426
    },
    {
      "text": " ",
      "start": 68.3,
      "end": 68.32,
      "type": "spacing",
      "logprob": -0.000020265373677830212
    },
    {
      "text": "they're",
      "start": 68.32,
      "end": 68.41,
      "type": "word",
      "logprob": -0.000011784572263552815
    },
    {
      "text": " ",
      "start": 68.41,
      "end": 68.44,
      "type": "spacing",
      "logprob": -0.00008725739462533966
    },
    {
      "text": "looking",
      "start": 68.44,
      "end": 68.74,
      "type": "word",
      "logprob": -0.00008725739462533966
    },
    {
      "text": " ",
      "start": 68.74,
      "end": 68.78,
      "type": "spacing",
      "logprob": -0.000004410734163684538
    },
    {
      "text": "for?",
      "start": 68.78,
      "end": 69,
      "type": "word",
      "logprob": -0.00003069488423079747
    },
    {
      "text": " ",
      "start": 69,
      "end": 69,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "Oh,",
      "start": 71,
      "end": 71.58,
      "type": "word",
      "logprob": -0.000023523671795070793
    },
    {
      "text": " ",
      "start": 71.58,
      "end": 71.6,
      "type": "spacing",
      "logprob": -0.00024959311122074723
    },
    {
      "text": "I",
      "start": 71.6,
      "end": 71.61,
      "type": "word",
      "logprob": -0.00024959311122074723
    },
    {
      "text": " ",
      "start": 71.61,
      "end": 71.64,
      "type": "spacing",
      "logprob": -0.000024437606043647975
    },
    {
      "text": "see",
      "start": 71.64,
      "end": 71.76,
      "type": "word",
      "logprob": -0.000024437606043647975
    },
    {
      "text": " ",
      "start": 71.76,
      "end": 71.78,
      "type": "spacing",
      "logprob": -0.0000011920922133867862
    },
    {
      "text": "you",
      "start": 71.78,
      "end": 71.84,
      "type": "word",
      "logprob": -0.0000011920922133867862
    },
    {
      "text": " ",
      "start": 71.84,
      "end": 71.94,
      "type": "spacing",
      "logprob": -0.8990589380264282
    },
    {
      "text": "stumbled",
      "start": 71.94,
      "end": 72.14,
      "type": "word",
      "logprob": -0.8990589380264282
    },
    {
      "text": " ",
      "start": 72.14,
      "end": 72.18,
      "type": "spacing",
      "logprob": -0.000030874729418428615
    },
    {
      "text": "across",
      "start": 72.18,
      "end": 72.44,
      "type": "word",
      "logprob": -0.000030874729418428615
    },
    {
      "text": " ",
      "start": 72.44,
      "end": 72.46,
      "type": "spacing",
      "logprob": -8.344646857949556e-7
    },
    {
      "text": "my",
      "start": 72.46,
      "end": 72.58,
      "type": "word",
      "logprob": -8.344646857949556e-7
    },
    {
      "text": " ",
      "start": 72.58,
      "end": 72.6,
      "type": "spacing",
      "logprob": -0.00007724463648628443
    },
    {
      "text": "board.",
      "start": 72.6,
      "end": 73.3,
      "type": "word",
      "logprob": -0.00006450960785286952
    },
    {
      "text": " ",
      "start": 73.3,
      "end": 73.34,
      "type": "spacing",
      "logprob": -1.1920928244535389e-7
    },
    {
      "text": "I'm",
      "start": 73.34,
      "end": 73.4,
      "type": "word",
      "logprob": -0.0000025033905307244217
    },
    {
      "text": " ",
      "start": 73.4,
      "end": 73.42,
      "type": "spacing",
      "logprob": -0.0000010728830375228426
    },
    {
      "text": "not",
      "start": 73.42,
      "end": 73.53,
      "type": "word",
      "logprob": -0.0000010728830375228426
    },
    {
      "text": " ",
      "start": 73.53,
      "end": 73.56,
      "type": "spacing",
      "logprob": -0.00014482879487331957
    },
    {
      "text": "one",
      "start": 73.56,
      "end": 73.6,
      "type": "word",
      "logprob": -0.00014482879487331957
    },
    {
      "text": " ",
      "start": 73.6,
      "end": 73.64,
      "type": "spacing",
      "logprob": -0.000002264974000354414
    },
    {
      "text": "of",
      "start": 73.64,
      "end": 73.67,
      "type": "word",
      "logprob": -0.000002264974000354414
    },
    {
      "text": " ",
      "start": 73.67,
      "end": 73.7,
      "type": "spacing",
      "logprob": -0.000016569954823353328
    },
    {
      "text": "those",
      "start": 73.7,
      "end": 73.85,
      "type": "word",
      "logprob": -0.000016569954823353328
    },
    {
      "text": " ",
      "start": 73.85,
      "end": 73.86,
      "type": "spacing",
      "logprob": -0.000008821448318485636
    },
    {
      "text": "weirdos,",
      "start": 73.86,
      "end": 74.2,
      "type": "word",
      "logprob": -0.0019075749197554615
    },
    {
      "text": " ",
      "start": 74.2,
      "end": 74.34,
      "type": "spacing",
      "logprob": -4.768370445162873e-7
    },
    {
      "text": "I",
      "start": 74.34,
      "end": 74.4,
      "type": "word",
      "logprob": -4.768370445162873e-7
    },
    {
      "text": " ",
      "start": 74.4,
      "end": 74.44,
      "type": "spacing",
      "logprob": -0.000007390948667307384
    },
    {
      "text": "swear.",
      "start": 74.44,
      "end": 75.06,
      "type": "word",
      "logprob": -0.00000615912388942282
    },
    {
      "text": " ",
      "start": 75.06,
      "end": 75.1,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "He's",
      "start": 75.1,
      "end": 75.25,
      "type": "word",
      "logprob": -0.000004589546961142332
    },
    {
      "text": " ",
      "start": 75.25,
      "end": 75.25,
      "type": "spacing",
      "logprob": -0.0000029802276912960224
    },
    {
      "text": "a",
      "start": 75.25,
      "end": 75.25,
      "type": "word",
      "logprob": -0.0000029802276912960224
    },
    {
      "text": " ",
      "start": 75.25,
      "end": 75.28,
      "type": "spacing",
      "logprob": -0.0000014305104514278355
    },
    {
      "text": "little",
      "start": 75.28,
      "end": 75.4,
      "type": "word",
      "logprob": -0.0000014305104514278355
    },
    {
      "text": " ",
      "start": 75.4,
      "end": 75.46,
      "type": "spacing",
      "logprob": -0.00003731181277544238
    },
    {
      "text": "weird.",
      "start": 75.46,
      "end": 75.62,
      "type": "word",
      "logprob": -0.00003111304552660954
    },
    {
      "text": " ",
      "start": 75.62,
      "end": 75.62,
      "type": "spacing",
      "logprob": -0.00003015949550899677
    },
    {
      "text": "I'm",
      "start": 76.64,
      "end": 76.75,
      "type": "word",
      "logprob": -0.000011086311512068884
    },
    {
      "text": " ",
      "start": 76.75,
      "end": 76.8,
      "type": "spacing",
      "logprob": -0.00007271502545336261
    },
    {
      "text": "MJ.",
      "start": 76.8,
      "end": 77.06,
      "type": "word",
      "logprob": -0.00004851642006305686
    },
    {
      "text": " ",
      "start": 77.06,
      "end": 78.039,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "You",
      "start": 78.08,
      "end": 78.2,
      "type": "word",
      "logprob": -0.00811848696321249
    },
    {
      "text": " ",
      "start": 78.2,
      "end": 78.32,
      "type": "spacing",
      "logprob": -0.0000040531076592742465
    },
    {
      "text": "two",
      "start": 78.32,
      "end": 78.44,
      "type": "word",
      "logprob": -0.0000040531076592742465
    },
    {
      "text": " ",
      "start": 78.44,
      "end": 78.46,
      "type": "spacing",
      "logprob": -0.0000015497195136049413
    },
    {
      "text": "don't",
      "start": 78.46,
      "end": 78.58,
      "type": "word",
      "logprob": -0.0000021219227846813737
    },
    {
      "text": " ",
      "start": 78.58,
      "end": 78.59,
      "type": "spacing",
      "logprob": -0.000008940656698541716
    },
    {
      "text": "know",
      "start": 78.59,
      "end": 78.66,
      "type": "word",
      "logprob": -0.000008940656698541716
    },
    {
      "text": " ",
      "start": 78.66,
      "end": 78.68,
      "type": "spacing",
      "logprob": -0.000053165931603871286
    },
    {
      "text": "each",
      "start": 78.68,
      "end": 78.78,
      "type": "word",
      "logprob": -0.000053165931603871286
    },
    {
      "text": " ",
      "start": 78.78,
      "end": 78.82,
      "type": "spacing",
      "logprob": -0.000010847986231965479
    },
    {
      "text": "other?",
      "start": 78.82,
      "end": 78.98,
      "type": "word",
      "logprob": -0.0012992224163402473
    },
    {
      "text": " ",
      "start": 78.98,
      "end": 78.98,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "Yo,",
      "start": 81.62,
      "end": 81.72,
      "type": "word",
      "logprob": -0.0011566771039118369
    },
    {
      "text": " ",
      "start": 81.72,
      "end": 81.94,
      "type": "spacing",
      "logprob": -0.0005199749139137566
    },
    {
      "text": "Spider-Man,",
      "start": 81.94,
      "end": 82.52,
      "type": "word",
      "logprob": -0.020411769467913968
    },
    {
      "text": " ",
      "start": 82.52,
      "end": 82.76,
      "type": "spacing",
      "logprob": -0.00003158996332786046
    },
    {
      "text": "you",
      "start": 82.76,
      "end": 82.8,
      "type": "word",
      "logprob": -0.00003158996332786046
    },
    {
      "text": " ",
      "start": 82.8,
      "end": 82.84,
      "type": "spacing",
      "logprob": -0.00025459862081333995
    },
    {
      "text": "okay?",
      "start": 82.84,
      "end": 83.12,
      "type": "word",
      "logprob": -0.000207731971386238
    },
    {
      "text": " ",
      "start": 83.12,
      "end": 83.74,
      "type": "spacing",
      "logprob": -0.012678731232881546
    },
    {
      "text": "You",
      "start": 83.74,
      "end": 83.8,
      "type": "word",
      "logprob": -0.012678731232881546
    },
    {
      "text": " ",
      "start": 83.8,
      "end": 83.92,
      "type": "spacing",
      "logprob": -0.00004625213477993384
    },
    {
      "text": "get",
      "start": 83.92,
      "end": 84.02,
      "type": "word",
      "logprob": -0.00004625213477993384
    },
    {
      "text": " ",
      "start": 84.02,
      "end": 84.06,
      "type": "spacing",
      "logprob": -0.000016093124941107817
    },
    {
      "text": "rid",
      "start": 84.06,
      "end": 84.14,
      "type": "word",
      "logprob": -0.000016093124941107817
    },
    {
      "text": " ",
      "start": 84.14,
      "end": 84.16,
      "type": "spacing",
      "logprob": -1.1920928244535389e-7
    },
    {
      "text": "of",
      "start": 84.16,
      "end": 84.21,
      "type": "word",
      "logprob": -1.1920928244535389e-7
    },
    {
      "text": " ",
      "start": 84.21,
      "end": 84.24,
      "type": "spacing",
      "logprob": -0.000097508447652217
    },
    {
      "text": "that",
      "start": 84.24,
      "end": 84.34,
      "type": "word",
      "logprob": -0.000097508447652217
    },
    {
      "text": " ",
      "start": 84.34,
      "end": 84.38,
      "type": "spacing",
      "logprob": -0.000014424220353248529
    },
    {
      "text": "monster",
      "start": 84.38,
      "end": 84.7,
      "type": "word",
      "logprob": -0.000014424220353248529
    },
    {
      "text": " ",
      "start": 84.7,
      "end": 84.74,
      "type": "spacing",
      "logprob": -0.000019430925021879375
    },
    {
      "text": "inside",
      "start": 84.74,
      "end": 85.08,
      "type": "word",
      "logprob": -0.000019430925021879375
    },
    {
      "text": " ",
      "start": 85.08,
      "end": 85.12,
      "type": "spacing",
      "logprob": -0.0013293724041432142
    },
    {
      "text": "you?",
      "start": 85.12,
      "end": 85.2,
      "type": "word",
      "logprob": -0.0010007545656662842
    },
    {
      "text": " ",
      "start": 85.2,
      "end": 86.04,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "I",
      "start": 86.04,
      "end": 86.06,
      "type": "word",
      "logprob": -0.03366709500551224
    },
    {
      "text": " ",
      "start": 86.06,
      "end": 86.07,
      "type": "spacing",
      "logprob": -0.000005602820692729438
    },
    {
      "text": "think",
      "start": 86.07,
      "end": 86.22,
      "type": "word",
      "logprob": -0.000005602820692729438
    },
    {
      "text": " ",
      "start": 86.22,
      "end": 86.28,
      "type": "spacing",
      "logprob": -0.00005352353764465079
    },
    {
      "text": "I",
      "start": 86.28,
      "end": 86.3,
      "type": "word",
      "logprob": -0.00005352353764465079
    },
    {
      "text": " ",
      "start": 86.3,
      "end": 86.399,
      "type": "spacing",
      "logprob": -0.0022205475252121687
    },
    {
      "text": "might",
      "start": 86.4,
      "end": 86.48,
      "type": "word",
      "logprob": -0.0022205475252121687
    },
    {
      "text": " ",
      "start": 86.48,
      "end": 86.56,
      "type": "spacing",
      "logprob": -0.00006997340824455023
    },
    {
      "text": "go",
      "start": 86.56,
      "end": 86.7,
      "type": "word",
      "logprob": -0.00006997340824455023
    },
    {
      "text": " ",
      "start": 86.7,
      "end": 86.78,
      "type": "spacing",
      "logprob": -0.0007114263135008514
    },
    {
      "text": "see",
      "start": 86.78,
      "end": 86.88,
      "type": "word",
      "logprob": -0.0007114263135008514
    },
    {
      "text": " ",
      "start": 86.88,
      "end": 86.92,
      "type": "spacing",
      "logprob": -0.00000822540732769994
    },
    {
      "text": "how",
      "start": 86.92,
      "end": 87.07,
      "type": "word",
      "logprob": -0.00000822540732769994
    },
    {
      "text": " ",
      "start": 87.07,
      "end": 87.16,
      "type": "spacing",
      "logprob": -0.001001690630801022
    },
    {
      "text": "MJ's",
      "start": 87.16,
      "end": 87.5,
      "type": "word",
      "logprob": -0.0006224240059964359
    },
    {
      "text": " ",
      "start": 87.5,
      "end": 87.56,
      "type": "spacing",
      "logprob": -0.000011801649634435307
    },
    {
      "text": "doing.",
      "start": 87.56,
      "end": 88.04,
      "type": "word",
      "logprob": -0.00000983470802869609
    },
    {
      "text": " ",
      "start": 88.04,
      "end": 88.04,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "You're",
      "start": 90.1,
      "end": 90.22,
      "type": "word",
      "logprob": -0.000018298330019206333
    },
    {
      "text": " ",
      "start": 90.22,
      "end": 90.28,
      "type": "spacing",
      "logprob": -2.3841855067985307e-7
    },
    {
      "text": "not",
      "start": 90.28,
      "end": 90.44,
      "type": "word",
      "logprob": -2.3841855067985307e-7
    },
    {
      "text": " ",
      "start": 90.44,
      "end": 90.48,
      "type": "spacing",
      "logprob": -0.0028688248712569475
    },
    {
      "text": "gonna",
      "start": 90.48,
      "end": 90.64,
      "type": "word",
      "logprob": -0.0028688248712569475
    },
    {
      "text": " ",
      "start": 90.64,
      "end": 90.66,
      "type": "spacing",
      "logprob": -0.0001554368354845792
    },
    {
      "text": "get",
      "start": 90.66,
      "end": 90.74,
      "type": "word",
      "logprob": -0.0001554368354845792
    },
    {
      "text": " ",
      "start": 90.74,
      "end": 90.8,
      "type": "spacing",
      "logprob": -0.000016093124941107817
    },
    {
      "text": "away",
      "start": 90.8,
      "end": 91,
      "type": "word",
      "logprob": -0.000016093124941107817
    },
    {
      "text": " ",
      "start": 91,
      "end": 91.04,
      "type": "spacing",
      "logprob": -0.0000010728830375228426
    },
    {
      "text": "with",
      "start": 91.04,
      "end": 91.14,
      "type": "word",
      "logprob": -0.0000010728830375228426
    },
    {
      "text": " ",
      "start": 91.14,
      "end": 91.2,
      "type": "spacing",
      "logprob": -0.0000066756979322235566
    },
    {
      "text": "this.",
      "start": 91.2,
      "end": 91.42,
      "type": "word",
      "logprob": -0.000005364400202267916
    },
    {
      "text": " ",
      "start": 91.42,
      "end": 91.42,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "But",
      "start": 92.48,
      "end": 92.6,
      "type": "word",
      "logprob": -0.000027656173188006505
    },
    {
      "text": " ",
      "start": 92.6,
      "end": 92.66,
      "type": "spacing",
      "logprob": -4.768370445162873e-7
    },
    {
      "text": "he",
      "start": 92.66,
      "end": 92.82,
      "type": "word",
      "logprob": -4.768370445162873e-7
    },
    {
      "text": " ",
      "start": 92.82,
      "end": 92.9,
      "type": "spacing",
      "logprob": -0.0000016689286894688848
    },
    {
      "text": "might.",
      "start": 92.9,
      "end": 93.32,
      "type": "word",
      "logprob": -0.0000013907739078907373
    },
    {
      "text": " ",
      "start": 93.32,
      "end": 93.32,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "I",
      "start": 96.22,
      "end": 96.24,
      "type": "word",
      "logprob": -0.00010156115604331717
    },
    {
      "text": " ",
      "start": 96.24,
      "end": 96.28,
      "type": "spacing",
      "logprob": -0.00001728519782773219
    },
    {
      "text": "didn't",
      "start": 96.28,
      "end": 96.38,
      "type": "word",
      "logprob": -0.00001255661080297917
    },
    {
      "text": " ",
      "start": 96.38,
      "end": 96.4,
      "type": "spacing",
      "logprob": -0.000013351351299206726
    },
    {
      "text": "know",
      "start": 96.4,
      "end": 96.5,
      "type": "word",
      "logprob": -0.000013351351299206726
    },
    {
      "text": " ",
      "start": 96.5,
      "end": 96.54,
      "type": "spacing",
      "logprob": -0.0000032186455882765586
    },
    {
      "text": "you",
      "start": 96.54,
      "end": 96.6,
      "type": "word",
      "logprob": -0.0000032186455882765586
    },
    {
      "text": " ",
      "start": 96.6,
      "end": 96.64,
      "type": "spacing",
      "logprob": -0.00034028460504487157
    },
    {
      "text": "could",
      "start": 96.64,
      "end": 96.72,
      "type": "word",
      "logprob": -0.00034028460504487157
    },
    {
      "text": " ",
      "start": 96.72,
      "end": 96.76,
      "type": "spacing",
      "logprob": -2.3841855067985307e-7
    },
    {
      "text": "get",
      "start": 96.76,
      "end": 96.88,
      "type": "word",
      "logprob": -2.3841855067985307e-7
    },
    {
      "text": " ",
      "start": 96.88,
      "end": 96.92,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "that",
      "start": 96.92,
      "end": 97.06,
      "type": "word",
      "logprob": 0
    },
    {
      "text": " ",
      "start": 97.06,
      "end": 97.1,
      "type": "spacing",
      "logprob": -8.344646857949556e-7
    },
    {
      "text": "big.",
      "start": 97.1,
      "end": 97.32,
      "type": "word",
      "logprob": -0.0000011324874833462673
    },
    {
      "text": " ",
      "start": 97.32,
      "end": 97.32,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "Whoa,",
      "start": 99.66,
      "end": 99.9,
      "type": "word",
      "logprob": -0.0056938805575555305
    },
    {
      "text": " ",
      "start": 99.9,
      "end": 100.15,
      "type": "spacing",
      "logprob": -0.0001864259538706392
    },
    {
      "text": "hey,",
      "start": 100.15,
      "end": 100.17,
      "type": "word",
      "logprob": -0.00013987907004064937
    },
    {
      "text": " ",
      "start": 100.17,
      "end": 100.35,
      "type": "spacing",
      "logprob": -0.000007271740287251305
    },
    {
      "text": "hey,",
      "start": 100.35,
      "end": 100.35,
      "type": "word",
      "logprob": -0.0000055730144765675504
    },
    {
      "text": " ",
      "start": 100.35,
      "end": 100.72,
      "type": "spacing",
      "logprob": -0.000021576648578047752
    },
    {
      "text": "hey.",
      "start": 100.72,
      "end": 100.73,
      "type": "word",
      "logprob": -0.000016182486433535814
    },
    {
      "text": " ",
      "start": 100.73,
      "end": 100.73,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "I'm",
      "start": 100.73,
      "end": 100.73,
      "type": "word",
      "logprob": -0.00005042341823961275
    },
    {
      "text": " ",
      "start": 100.73,
      "end": 100.73,
      "type": "spacing",
      "logprob": -0.00023100091493688524
    },
    {
      "text": "sorry,",
      "start": 100.73,
      "end": 100.73,
      "type": "word",
      "logprob": -0.00019252063066114525
    },
    {
      "text": " ",
      "start": 100.73,
      "end": 100.74,
      "type": "spacing",
      "logprob": -2.3841855067985307e-7
    },
    {
      "text": "but",
      "start": 100.74,
      "end": 100.82,
      "type": "word",
      "logprob": -2.3841855067985307e-7
    },
    {
      "text": " ",
      "start": 100.82,
      "end": 100.86,
      "type": "spacing",
      "logprob": -0.0000010728830375228426
    },
    {
      "text": "you're",
      "start": 100.86,
      "end": 100.94,
      "type": "word",
      "logprob": -6.556507941013479e-7
    },
    {
      "text": " ",
      "start": 100.94,
      "end": 100.98,
      "type": "spacing",
      "logprob": -0.000005602820692729438
    },
    {
      "text": "in",
      "start": 100.98,
      "end": 101.04,
      "type": "word",
      "logprob": -0.000005602820692729438
    },
    {
      "text": " ",
      "start": 101.04,
      "end": 101.08,
      "type": "spacing",
      "logprob": -0.000017046782886609435
    },
    {
      "text": "danger.",
      "start": 101.08,
      "end": 101.36,
      "type": "word",
      "logprob": -0.000014611528188522374
    },
    {
      "text": " ",
      "start": 101.36,
      "end": 101.46,
      "type": "spacing",
      "logprob": -0.00031990656862035394
    },
    {
      "text": "You",
      "start": 101.46,
      "end": 101.56,
      "type": "word",
      "logprob": -0.00031990656862035394
    },
    {
      "text": " ",
      "start": 101.56,
      "end": 101.64,
      "type": "spacing",
      "logprob": -0.000024437606043647975
    },
    {
      "text": "just",
      "start": 101.64,
      "end": 101.75,
      "type": "word",
      "logprob": -0.000024437606043647975
    },
    {
      "text": " ",
      "start": 101.75,
      "end": 101.8,
      "type": "spacing",
      "logprob": -0.00009274052717955783
    },
    {
      "text": "have",
      "start": 101.8,
      "end": 101.92,
      "type": "word",
      "logprob": -0.00009274052717955783
    },
    {
      "text": " ",
      "start": 101.92,
      "end": 101.96,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "to",
      "start": 101.96,
      "end": 102.04,
      "type": "word",
      "logprob": 0
    },
    {
      "text": " ",
      "start": 102.04,
      "end": 102.06,
      "type": "spacing",
      "logprob": -0.000005483612312673358
    },
    {
      "text": "trust",
      "start": 102.06,
      "end": 102.28,
      "type": "word",
      "logprob": -0.000005483612312673358
    },
    {
      "text": " ",
      "start": 102.28,
      "end": 102.32,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "me.",
      "start": 102.32,
      "end": 102.42,
      "type": "word",
      "logprob": 0
    },
    {
      "text": " ",
      "start": 102.42,
      "end": 103,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "Fine.",
      "start": 103,
      "end": 103.54,
      "type": "word",
      "logprob": -0.0004272035788631001
    },
    {
      "text": " ",
      "start": 103.54,
      "end": 103.6,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "This",
      "start": 103.6,
      "end": 103.74,
      "type": "word",
      "logprob": -0.00003635817120084539
    },
    {
      "text": " ",
      "start": 103.74,
      "end": 103.8,
      "type": "spacing",
      "logprob": -0.000029205850296420977
    },
    {
      "text": "whole",
      "start": 103.8,
      "end": 104.24,
      "type": "word",
      "logprob": -0.000029205850296420977
    },
    {
      "text": " ",
      "start": 104.24,
      "end": 104.28,
      "type": "spacing",
      "logprob": -0.000007867782187531702
    },
    {
      "text": "thing",
      "start": 104.28,
      "end": 104.51,
      "type": "word",
      "logprob": -0.000007867782187531702
    },
    {
      "text": " ",
      "start": 104.51,
      "end": 104.7,
      "type": "spacing",
      "logprob": -0.00007843663479434326
    },
    {
      "text": "can",
      "start": 104.7,
      "end": 104.8,
      "type": "word",
      "logprob": -0.00007843663479434326
    },
    {
      "text": " ",
      "start": 104.8,
      "end": 104.82,
      "type": "spacing",
      "logprob": -0.0000020265558760002023
    },
    {
      "text": "get",
      "start": 104.82,
      "end": 104.96,
      "type": "word",
      "logprob": -0.0000020265558760002023
    },
    {
      "text": " ",
      "start": 104.96,
      "end": 105.16,
      "type": "spacing",
      "logprob": -0.0000040531076592742465
    },
    {
      "text": "really",
      "start": 105.16,
      "end": 105.51,
      "type": "word",
      "logprob": -0.0000040531076592742465
    },
    {
      "text": " ",
      "start": 105.51,
      "end": 105.54,
      "type": "spacing",
      "logprob": -0.0002882065309677273
    },
    {
      "text": "scary.",
      "start": 105.54,
      "end": 106.04,
      "type": "word",
      "logprob": -0.0002901133169264843
    },
    {
      "text": " ",
      "start": 106.04,
      "end": 106.06,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "Who",
      "start": 106.06,
      "end": 106.12,
      "type": "word",
      "logprob": -0.00009417090768693015
    },
    {
      "text": " ",
      "start": 106.12,
      "end": 106.16,
      "type": "spacing",
      "logprob": -0.000009059865078597795
    },
    {
      "text": "the",
      "start": 106.16,
      "end": 106.24,
      "type": "word",
      "logprob": -0.000009059865078597795
    },
    {
      "text": " ",
      "start": 106.24,
      "end": 106.3,
      "type": "spacing",
      "logprob": -0.0000036954811548639555
    },
    {
      "text": "hell",
      "start": 106.3,
      "end": 106.37,
      "type": "word",
      "logprob": -0.0000036954811548639555
    },
    {
      "text": " ",
      "start": 106.37,
      "end": 106.42,
      "type": "spacing",
      "logprob": -0.0000034570634852570947
    },
    {
      "text": "is",
      "start": 106.46,
      "end": 106.58,
      "type": "word",
      "logprob": -0.0000034570634852570947
    },
    {
      "text": " ",
      "start": 106.58,
      "end": 106.59,
      "type": "spacing",
      "logprob": -1.1920928244535389e-7
    },
    {
      "text": "that?",
      "start": 106.59,
      "end": 106.86,
      "type": "word",
      "logprob": -1.668929883180681e-7
    },
    {
      "text": " ",
      "start": 106.86,
      "end": 107.02,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "Who",
      "start": 107.02,
      "end": 107.12,
      "type": "word",
      "logprob": -0.0014118712861090899
    },
    {
      "text": " ",
      "start": 107.12,
      "end": 107.16,
      "type": "spacing",
      "logprob": -0.0000015497195136049413
    },
    {
      "text": "the",
      "start": 107.16,
      "end": 107.25,
      "type": "word",
      "logprob": -0.0000015497195136049413
    },
    {
      "text": " ",
      "start": 107.25,
      "end": 107.25,
      "type": "spacing",
      "logprob": -0.0000014305104514278355
    },
    {
      "text": "hell",
      "start": 107.25,
      "end": 107.32,
      "type": "word",
      "logprob": -0.0000014305104514278355
    },
    {
      "text": " ",
      "start": 107.32,
      "end": 107.34,
      "type": "spacing",
      "logprob": -3.576278118089249e-7
    },
    {
      "text": "are",
      "start": 107.34,
      "end": 107.42,
      "type": "word",
      "logprob": -3.576278118089249e-7
    },
    {
      "text": " ",
      "start": 107.42,
      "end": 107.5,
      "type": "spacing",
      "logprob": -0.000002861018856492592
    },
    {
      "text": "you?",
      "start": 107.5,
      "end": 107.62,
      "type": "word",
      "logprob": -0.000002264973403498516
    },
    {
      "text": " ",
      "start": 107.62,
      "end": 107.84,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "Frank,",
      "start": 107.84,
      "end": 108.02,
      "type": "word",
      "logprob": -0.6206734677155801
    },
    {
      "text": " ",
      "start": 108.02,
      "end": 108.06,
      "type": "spacing",
      "logprob": -0.00002109982233378105
    },
    {
      "text": "please,",
      "start": 108.06,
      "end": 108.42,
      "type": "word",
      "logprob": -0.007858415077082879
    },
    {
      "text": " ",
      "start": 108.42,
      "end": 108.56,
      "type": "spacing",
      "logprob": -2.3841855067985307e-7
    },
    {
      "text": "I",
      "start": 108.56,
      "end": 108.7,
      "type": "word",
      "logprob": -2.3841855067985307e-7
    },
    {
      "text": " ",
      "start": 108.7,
      "end": 108.74,
      "type": "spacing",
      "logprob": -0.000014781842764932662
    },
    {
      "text": "don't",
      "start": 108.74,
      "end": 108.89,
      "type": "word",
      "logprob": -0.000044868690019939093
    },
    {
      "text": " ",
      "start": 108.89,
      "end": 108.94,
      "type": "spacing",
      "logprob": -0.000023841574147809297
    },
    {
      "text": "have",
      "start": 108.94,
      "end": 109.06,
      "type": "word",
      "logprob": -0.000023841574147809297
    },
    {
      "text": " ",
      "start": 109.06,
      "end": 109.1,
      "type": "spacing",
      "logprob": -0.00002992108420585282
    },
    {
      "text": "anywhere",
      "start": 109.1,
      "end": 109.38,
      "type": "word",
      "logprob": -0.00002992108420585282
    },
    {
      "text": " ",
      "start": 109.38,
      "end": 109.42,
      "type": "spacing",
      "logprob": -0.0000020265558760002023
    },
    {
      "text": "else",
      "start": 109.42,
      "end": 109.55,
      "type": "word",
      "logprob": -0.0000020265558760002023
    },
    {
      "text": " ",
      "start": 109.55,
      "end": 109.6,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "to",
      "start": 109.6,
      "end": 109.7,
      "type": "word",
      "logprob": 0
    },
    {
      "text": " ",
      "start": 109.7,
      "end": 109.74,
      "type": "spacing",
      "logprob": -2.3841855067985307e-7
    },
    {
      "text": "go.",
      "start": 109.74,
      "end": 109.94,
      "type": "word",
      "logprob": -1.9868212793502002e-7
    },
    {
      "text": " ",
      "start": 109.94,
      "end": 110.78,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "By",
      "start": 110.78,
      "end": 110.87,
      "type": "word",
      "logprob": -0.3190203905105591
    },
    {
      "text": " ",
      "start": 110.87,
      "end": 110.88,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "the",
      "start": 110.88,
      "end": 110.98,
      "type": "word",
      "logprob": 0
    },
    {
      "text": " ",
      "start": 110.98,
      "end": 111.02,
      "type": "spacing",
      "logprob": -0.000003576272320060525
    },
    {
      "text": "people",
      "start": 111.02,
      "end": 111.28,
      "type": "word",
      "logprob": -0.000003576272320060525
    },
    {
      "text": " ",
      "start": 111.28,
      "end": 111.32,
      "type": "spacing",
      "logprob": -0.000007748573807475623
    },
    {
      "text": "who",
      "start": 111.32,
      "end": 111.44,
      "type": "word",
      "logprob": -0.000007748573807475623
    },
    {
      "text": " ",
      "start": 111.44,
      "end": 111.5,
      "type": "spacing",
      "logprob": -8.344646857949556e-7
    },
    {
      "text": "love",
      "start": 111.5,
      "end": 111.68,
      "type": "word",
      "logprob": -8.344646857949556e-7
    },
    {
      "text": " ",
      "start": 111.68,
      "end": 111.74,
      "type": "spacing",
      "logprob": -0.0000040531076592742465
    },
    {
      "text": "you.",
      "start": 111.74,
      "end": 111.86,
      "type": "word",
      "logprob": -0.012154045508623312
    },
    {
      "text": " ",
      "start": 111.86,
      "end": 112.54,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "Just",
      "start": 112.54,
      "end": 112.76,
      "type": "word",
      "logprob": -0.02076680399477482
    },
    {
      "text": " ",
      "start": 112.76,
      "end": 112.8,
      "type": "spacing",
      "logprob": -0.0002466136065777391
    },
    {
      "text": "gonna",
      "start": 112.8,
      "end": 112.98,
      "type": "word",
      "logprob": -0.0002466136065777391
    },
    {
      "text": " ",
      "start": 112.98,
      "end": 113.02,
      "type": "spacing",
      "logprob": -0.0019421778852120042
    },
    {
      "text": "let",
      "start": 113.02,
      "end": 113.16,
      "type": "word",
      "logprob": -0.0019421778852120042
    },
    {
      "text": " ",
      "start": 113.16,
      "end": 113.179,
      "type": "spacing",
      "logprob": -0.0014335366431623697
    },
    {
      "text": "him",
      "start": 113.18,
      "end": 113.2,
      "type": "word",
      "logprob": -0.0014335366431623697
    },
    {
      "text": " ",
      "start": 113.2,
      "end": 113.26,
      "type": "spacing",
      "logprob": -0.56412672996521
    },
    {
      "text": "go",
      "start": 113.26,
      "end": 113.38,
      "type": "word",
      "logprob": -0.56412672996521
    },
    {
      "text": " ",
      "start": 113.38,
      "end": 113.42,
      "type": "spacing",
      "logprob": -0.000038980677345534787
    },
    {
      "text": "by",
      "start": 113.42,
      "end": 113.48,
      "type": "word",
      "logprob": -0.000038980677345534787
    },
    {
      "text": " ",
      "start": 113.48,
      "end": 113.5,
      "type": "spacing",
      "logprob": -0.0015150746330618858
    },
    {
      "text": "himself?",
      "start": 113.5,
      "end": 114.06,
      "type": "word",
      "logprob": -0.0013269419952166572
    },
    {
      "text": " ",
      "start": 114.06,
      "end": 114.06,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "Who",
      "start": 115.32,
      "end": 115.33,
      "type": "word",
      "logprob": -0.3688816428184509
    },
    {
      "text": " ",
      "start": 115.33,
      "end": 115.33,
      "type": "spacing",
      "logprob": -0.00015066919149830937
    },
    {
      "text": "love",
      "start": 115.33,
      "end": 115.5,
      "type": "word",
      "logprob": -0.00015066919149830937
    },
    {
      "text": " ",
      "start": 115.5,
      "end": 115.58,
      "type": "spacing",
      "logprob": -0.000019192511899746023
    },
    {
      "text": "you",
      "start": 115.58,
      "end": 115.68,
      "type": "word",
      "logprob": -0.000019192511899746023
    },
    {
      "text": " ",
      "start": 115.68,
      "end": 115.74,
      "type": "spacing",
      "logprob": -0.000010847986231965479
    },
    {
      "text": "because",
      "start": 115.74,
      "end": 116.1,
      "type": "word",
      "logprob": -0.000010847986231965479
    },
    {
      "text": " ",
      "start": 116.1,
      "end": 116.16,
      "type": "spacing",
      "logprob": -7.152555099310121e-7
    },
    {
      "text": "you're",
      "start": 116.16,
      "end": 116.3,
      "type": "word",
      "logprob": -3.5762775496550603e-7
    },
    {
      "text": " ",
      "start": 116.3,
      "end": 116.399,
      "type": "spacing",
      "logprob": -0.000002861018856492592
    },
    {
      "text": "you.",
      "start": 116.4,
      "end": 116.64,
      "type": "word",
      "logprob": -0.000002145764142369444
    },
    {
      "text": " ",
      "start": 116.64,
      "end": 117.08,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "If",
      "start": 117.08,
      "end": 117.11,
      "type": "word",
      "logprob": -0.0009264229447580874
    },
    {
      "text": " ",
      "start": 117.11,
      "end": 117.14,
      "type": "spacing",
      "logprob": -0.000015616295058862306
    },
    {
      "text": "you're",
      "start": 117.14,
      "end": 117.24,
      "type": "word",
      "logprob": -0.00005262883223622339
    },
    {
      "text": " ",
      "start": 117.24,
      "end": 117.26,
      "type": "spacing",
      "logprob": -0.0005558376433327794
    },
    {
      "text": "gonna",
      "start": 117.26,
      "end": 117.5,
      "type": "word",
      "logprob": -0.0005558376433327794
    },
    {
      "text": " ",
      "start": 117.5,
      "end": 117.52,
      "type": "spacing",
      "logprob": -9.536738616588991e-7
    },
    {
      "text": "do",
      "start": 117.52,
      "end": 117.72,
      "type": "word",
      "logprob": -9.536738616588991e-7
    },
    {
      "text": " ",
      "start": 117.72,
      "end": 117.78,
      "type": "spacing",
      "logprob": -0.0003873551613651216
    },
    {
      "text": "something,",
      "start": 117.78,
      "end": 117.98,
      "type": "word",
      "logprob": -0.00034861964522860946
    },
    {
      "text": " ",
      "start": 117.98,
      "end": 118.02,
      "type": "spacing",
      "logprob": -0.000003933898824470816
    },
    {
      "text": "you",
      "start": 118.02,
      "end": 118.06,
      "type": "word",
      "logprob": -0.000003933898824470816
    },
    {
      "text": " ",
      "start": 118.06,
      "end": 118.14,
      "type": "spacing",
      "logprob": -0.016046548262238503
    },
    {
      "text": "better",
      "start": 118.14,
      "end": 118.34,
      "type": "word",
      "logprob": -0.016046548262238503
    },
    {
      "text": " ",
      "start": 118.34,
      "end": 118.38,
      "type": "spacing",
      "logprob": -2.3841855067985307e-7
    },
    {
      "text": "do",
      "start": 118.38,
      "end": 118.48,
      "type": "word",
      "logprob": -2.3841855067985307e-7
    },
    {
      "text": " ",
      "start": 118.48,
      "end": 118.52,
      "type": "spacing",
      "logprob": -1.1920928244535389e-7
    },
    {
      "text": "it",
      "start": 118.52,
      "end": 118.62,
      "type": "word",
      "logprob": -1.1920928244535389e-7
    },
    {
      "text": " ",
      "start": 118.62,
      "end": 118.66,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "now.",
      "start": 118.66,
      "end": 118.94,
      "type": "word",
      "logprob": 0
    },
    {
      "text": " ",
      "start": 118.94,
      "end": 118.94,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "And",
      "start": 121.16,
      "end": 121.2,
      "type": "word",
      "logprob": -0.003357133362442255
    },
    {
      "text": " ",
      "start": 121.2,
      "end": 121.26,
      "type": "spacing",
      "logprob": -0.000007510157047363464
    },
    {
      "text": "never",
      "start": 121.26,
      "end": 121.52,
      "type": "word",
      "logprob": -0.000007510157047363464
    },
    {
      "text": " ",
      "start": 121.52,
      "end": 121.6,
      "type": "spacing",
      "logprob": -0.0003251500893384218
    },
    {
      "text": "forget",
      "start": 121.6,
      "end": 121.94,
      "type": "word",
      "logprob": -0.0003251500893384218
    },
    {
      "text": " ",
      "start": 121.94,
      "end": 121.98,
      "type": "spacing",
      "logprob": -1.1920928244535389e-7
    },
    {
      "text": "that,",
      "start": 121.98,
      "end": 122.18,
      "type": "word",
      "logprob": -0.007648838311428108
    },
    {
      "text": " ",
      "start": 122.18,
      "end": 122.18,
      "type": "spacing",
      "logprob": -7.152555099310121e-7
    },
    {
      "text": "no",
      "start": 123.54,
      "end": 123.62,
      "type": "word",
      "logprob": -7.152555099310121e-7
    },
    {
      "text": " ",
      "start": 123.62,
      "end": 123.7,
      "type": "spacing",
      "logprob": -0.00003528532761265524
    },
    {
      "text": "matter",
      "start": 123.7,
      "end": 123.92,
      "type": "word",
      "logprob": -0.00003528532761265524
    },
    {
      "text": " ",
      "start": 123.92,
      "end": 123.98,
      "type": "spacing",
      "logprob": -5.960462772236497e-7
    },
    {
      "text": "how",
      "start": 123.98,
      "end": 124.08,
      "type": "word",
      "logprob": -5.960462772236497e-7
    },
    {
      "text": " ",
      "start": 124.08,
      "end": 124.26,
      "type": "spacing",
      "logprob": -4.768370445162873e-7
    },
    {
      "text": "powerful",
      "start": 124.26,
      "end": 124.82,
      "type": "word",
      "logprob": -4.768370445162873e-7
    },
    {
      "text": " ",
      "start": 124.82,
      "end": 124.89,
      "type": "spacing",
      "logprob": -0.000005483612312673358
    },
    {
      "text": "you",
      "start": 124.89,
      "end": 124.94,
      "type": "word",
      "logprob": -0.000005483612312673358
    },
    {
      "text": " ",
      "start": 124.94,
      "end": 125,
      "type": "spacing",
      "logprob": -0.0017244244227185845
    },
    {
      "text": "become...",
      "start": 125,
      "end": 125.54,
      "type": "word",
      "logprob": -0.0011517223059248256
    },
    {
      "text": " ",
      "start": 125.54,
      "end": 125.54,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "I",
      "start": 128.02,
      "end": 128.08,
      "type": "word",
      "logprob": -0.0002454218047205359
    },
    {
      "text": " ",
      "start": 128.08,
      "end": 128.14,
      "type": "spacing",
      "logprob": -0.0005486889858730137
    },
    {
      "text": "miss",
      "start": 128.14,
      "end": 128.3,
      "type": "word",
      "logprob": -0.0005486889858730137
    },
    {
      "text": " ",
      "start": 128.3,
      "end": 128.34,
      "type": "spacing",
      "logprob": -0.000005722029527532868
    },
    {
      "text": "you,",
      "start": 128.34,
      "end": 128.38,
      "type": "word",
      "logprob": -0.00003415166656850488
    },
    {
      "text": " ",
      "start": 128.38,
      "end": 128.44,
      "type": "spacing",
      "logprob": -0.02199867181479931
    },
    {
      "text": "May.",
      "start": 128.44,
      "end": 128.6,
      "type": "word",
      "logprob": -0.0164999575335969
    },
    {
      "text": " ",
      "start": 128.6,
      "end": 128.6,
      "type": "spacing",
      "logprob": -0.0046617635525763035
    },
    {
      "text": "Huh.",
      "start": 136.44,
      "end": 136.6,
      "type": "word",
      "logprob": -0.05751908442470821
    },
    {
      "text": " ",
      "start": 136.6,
      "end": 137.28,
      "type": "spacing",
      "logprob": -0.006061978172510862
    },
    {
      "text": "All",
      "start": 137.28,
      "end": 137.329,
      "type": "word",
      "logprob": -0.006061978172510862
    },
    {
      "text": " ",
      "start": 137.329,
      "end": 137.38,
      "type": "spacing",
      "logprob": -0.0000033378546504536644
    },
    {
      "text": "right,",
      "start": 137.38,
      "end": 137.5,
      "type": "word",
      "logprob": -0.05807771781625585
    },
    {
      "text": " ",
      "start": 137.5,
      "end": 137.68,
      "type": "spacing",
      "logprob": -0.000007033323527139146
    },
    {
      "text": "just",
      "start": 137.68,
      "end": 137.88,
      "type": "word",
      "logprob": -0.000007033323527139146
    },
    {
      "text": " ",
      "start": 137.88,
      "end": 137.92,
      "type": "spacing",
      "logprob": -0.03586619347333908
    },
    {
      "text": "give",
      "start": 137.92,
      "end": 138,
      "type": "word",
      "logprob": -0.03586619347333908
    },
    {
      "text": " ",
      "start": 138,
      "end": 138.02,
      "type": "spacing",
      "logprob": -0.00015221867943182588
    },
    {
      "text": "me",
      "start": 138.02,
      "end": 138.08,
      "type": "word",
      "logprob": -0.00015221867943182588
    },
    {
      "text": " ",
      "start": 138.08,
      "end": 138.12,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "a",
      "start": 138.12,
      "end": 138.18,
      "type": "word",
      "logprob": 0
    },
    {
      "text": " ",
      "start": 138.18,
      "end": 138.22,
      "type": "spacing",
      "logprob": -0.00004017272294731811
    },
    {
      "text": "sec.",
      "start": 138.22,
      "end": 138.46,
      "type": "word",
      "logprob": -0.00003015934453109992
    },
    {
      "text": " ",
      "start": 138.46,
      "end": 138.46,
      "type": "spacing",
      "logprob": -0.00009345571743324399
    },
    {
      "text": "Give",
      "start": 139.74,
      "end": 139.82,
      "type": "word",
      "logprob": -0.00009345571743324399
    },
    {
      "text": " ",
      "start": 139.82,
      "end": 139.859,
      "type": "spacing",
      "logprob": -0.0000033378546504536644
    },
    {
      "text": "me",
      "start": 139.86,
      "end": 139.92,
      "type": "word",
      "logprob": -0.0000033378546504536644
    },
    {
      "text": " ",
      "start": 139.92,
      "end": 139.98,
      "type": "spacing",
      "logprob": 0
    },
    {
      "text": "a",
      "start": 139.98,
      "end": 140.04,
      "type": "word",
      "logprob": 0
    },
    {
      "text": " ",
      "start": 140.04,
      "end": 140.1,
      "type": "spacing",
      "logprob": -0.00003909988299710676
    },
    {
      "text": "sec.",
      "start": 140.1,
      "end": 140.36,
      "type": "word",
      "logprob": -0.00002932491224783007
    },
    {
      "text": " ",
      "start": 140.36,
      "end": 140.36,
      "type": "spacing",
      "logprob": -0.000005364403477869928
    },
    {
      "text": "I'm",
      "start": 141.54,
      "end": 141.6,
      "type": "word",
      "logprob": -0.000003774952953487324
    },
    {
      "text": " ",
      "start": 141.6,
      "end": 141.64,
      "type": "spacing",
      "logprob": -2.3841855067985307e-7
    },
    {
      "text": "not",
      "start": 141.64,
      "end": 141.74,
      "type": "word",
      "logprob": -2.3841855067985307e-7
    },
    {
      "text": " ",
      "start": 141.74,
      "end": 141.76,
      "type": "spacing",
      "logprob": -0.000005006777428206988
    },
    {
      "text": "in",
      "start": 141.76,
      "end": 141.84,
      "type": "word",
      "logprob": -0.000005006777428206988
    },
    {
      "text": " ",
      "start": 141.84,
      "end": 141.86,
      "type": "spacing",
      "logprob": -4.768370445162873e-7
    },
    {
      "text": "high",
      "start": 141.86,
      "end": 141.96,
      "type": "word",
      "logprob": -4.768370445162873e-7
    },
    {
      "text": " ",
      "start": 141.96,
      "end": 142.06,
      "type": "spacing",
      "logprob": -0.00002825220326485578
    },
    {
      "text": "school",
      "start": 142.06,
      "end": 142.26,
      "type": "word",
      "logprob": -0.00002825220326485578
    },
    {
      "text": " ",
      "start": 142.26,
      "end": 142.34,
      "type": "spacing",
      "logprob": -0.0002109781780745834
    },
    {
      "text": "anymore.",
      "start": 142.34,
      "end": 142.78,
      "type": "word",
      "logprob": -0.00018466551044582502
    },
    {
      "text": " ",
      "start": 142.78,
      "end": 143.46,
      "type": "spacing",
      "logprob": -0.00032217081752605736
    },
    {
      "text": "Oh,",
      "start": 143.46,
      "end": 144.48,
      "type": "word",
      "logprob": -0.0006770713371224701
    },
    {
      "text": " ",
      "start": 144.48,
      "end": 144.519,
      "type": "spacing",
      "logprob": -0.009732297621667385
    },
    {
      "text": "did",
      "start": 144.52,
      "end": 144.54,
      "type": "word",
      "logprob": -0.009732297621667385
    },
    {
      "text": " ",
      "start": 144.54,
      "end": 144.56,
      "type": "spacing",
      "logprob": -0.00003635817120084539
    },
    {
      "text": "you",
      "start": 144.56,
      "end": 144.68,
      "type": "word",
      "logprob": -0.00003635817120084539
    },
    {
      "text": " ",
      "start": 144.68,
      "end": 144.72,
      "type": "spacing",
      "logprob": -0.00012599628826137632
    },
    {
      "text": "stretch?",
      "start": 144.72,
      "end": 144.98,
      "type": "word",
      "logprob": -0.00011695209514073213
    },
    {
      "text": " ",
      "start": 144.98,
      "end": 145.1,
      "type": "spacing",
      "logprob": -0.023286327719688416
    },
    {
      "text": "Are",
      "start": 145.1,
      "end": 145.15,
      "type": "word",
      "logprob": -0.023286327719688416
    },
    {
      "text": " ",
      "start": 145.15,
      "end": 145.15,
      "type": "spacing",
      "logprob": -0.0000066756979322235566
    },
    {
      "text": "you",
      "start": 145.15,
      "end": 145.15,
      "type": "word",
      "logprob": -0.0000066756979322235566
    },
    {
      "text": " ",
      "start": 145.15,
      "end": 145.15,
      "type": "spacing",
      "logprob": -0.000019788545614574105
    },
    {
      "text": "a",
      "start": 145.15,
      "end": 145.15,
      "type": "word",
      "logprob": -0.000019788545614574105
    },
    {
      "text": " ",
      "start": 145.15,
      "end": 145.16,
      "type": "spacing",
      "logprob": -0.000010490362910786644
    },
    {
      "text": "big",
      "start": 145.16,
      "end": 145.24,
      "type": "word",
      "logprob": -0.000010490362910786644
    },
    {
      "text": " ",
      "start": 145.24,
      "end": 145.32,
      "type": "spacing",
      "logprob": -0.0003871168300975114
    },
    {
      "text": "stretcher?",
      "start": 145.32,
      "end": 145.66,
      "type": "word",
      "logprob": -0.00020022216858706088
    },
    {
      "text": " ",
      "start": 145.66,
      "end": 146.299,
      "type": "spacing",
      "logprob": -3.576278118089249e-7
    },
    {
      "text": "It's",
      "start": 146.3,
      "end": 146.5,
      "type": "word",
      "logprob": -0.000002503389595176486
    },
    {
      "text": " ",
      "start": 146.5,
      "end": 146.58,
      "type": "spacing",
      "logprob": -4.768370445162873e-7
    },
    {
      "text": "so",
      "start": 146.58,
      "end": 146.78,
      "type": "word",
      "logprob": -4.768370445162873e-7
    },
    {
      "text": " ",
      "start": 146.78,
      "end": 146.88,
      "type": "spacing",
      "logprob": -0.0000017881377516459906
    },
    {
      "text": "good",
      "start": 146.88,
      "end": 147.04,
      "type": "word",
      "logprob": -0.0000017881377516459906
    },
    {
      "text": " ",
      "start": 147.04,
      "end": 147.1,
      "type": "spacing",
      "logprob": -1.1920928244535389e-7
    },
    {
      "text": "for",
      "start": 147.1,
      "end": 147.2,
      "type": "word",
      "logprob": -1.1920928244535389e-7
    },
    {
      "text": " ",
      "start": 147.2,
      "end": 147.28,
      "type": "spacing",
      "logprob": -1.1920928244535389e-7
    },
    {
      "text": "you.",
      "start": 147.28,
      "end": 147.4,
      "type": "word",
      "logprob": -2.3841853113992784e-7
    },
    {
      "text": " ",
      "start": 147.4,
      "end": 148.34,
      "type": "spacing",
      "logprob": -0.00012373158824630082
    },
    {
      "text": "You",
      "start": 148.34,
      "end": 148.4,
      "type": "word",
      "logprob": -0.00012373158824630082
    },
    {
      "text": " ",
      "start": 148.4,
      "end": 148.44,
      "type": "spacing",
      "logprob": -0.0000017881377516459906
    },
    {
      "text": "ready?",
      "start": 148.44,
      "end": 148.62,
      "type": "word",
      "logprob": -0.000002145764597116795
    }
  ],
  "transcription_id": "Qimrc6F0xIpkLeTa9SdS",
  "audio_duration_secs": 161.610875
}