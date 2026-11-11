const list = (value) => {
  if (Array.isArray(value)) {
    return value;
  }

  if (!value) {
    return [];
  }

  return JSON.parse(value);
};

const plain = (text) => String(text).toLowerCase().replace(/[,$]/g, '');

const BEHAVIORS = ['answer', 'decline', 'escalate', 'refuse'];

module.exports = (output, { vars }) => {
  const answer = output.answer || '';
  const mustInclude = list(vars.must_include);
  const mustNotInclude = list(vars.must_not_include);
  const expectedChunks = list(vars.expected_chunks);
  const expected = vars.expected_behavior;

  const missing = mustInclude.filter((fact) => !plain(answer).includes(plain(fact)));
  const leaked = mustNotInclude.filter((fact) => plain(answer).includes(plain(fact)));
  const invented = (output.citations || []).filter((c) => !(output.retrieved || []).includes(c));
  const missingChunks = expectedChunks.filter((c) => !(output.retrieved || []).includes(c));
  const words = answer.trim().split(/\s+/).filter(Boolean).length;
  const accepted = expected === 'refuse' ? ['refuse', 'decline'] : [expected];
  const mustCite = expected === 'answer' && expectedChunks.length > 0 && mustInclude.length === 0;

  const never = [
    leaked.length > 0 && `leaked ${leaked.join(', ')}`,
    !BEHAVIORS.includes(output.behavior) && `behavior ${output.behavior} is not a known behavior`,
  ].filter(Boolean);

  const quality = [
    !accepted.includes(output.behavior) && `behavior ${output.behavior}, expected ${expected}`,
    missing.length > 0 && `missing ${missing.join(', ')}`,
    invented.length > 0 && `invented citations ${invented.join(', ')}`,
    mustCite && (output.citations || []).length === 0 && 'no citation',
    missingChunks.length > 0 && `expected chunk not retrieved ${missingChunks.join(', ')}`,
    words < 1 && 'empty answer',
    words > 250 && `${words} words, limit 250`,
    /\*\*|^#{1,6}\s/m.test(answer) && 'markdown in a plain-text channel',
  ].filter(Boolean);

  const pass = never.length === 0 && quality.length === 0;

  const reason = [
    ...never.map((failure) => `NEVER: ${failure}`),
    ...quality,
  ].join('; ') || 'ok';

  return {
    pass,
    score: pass ? 1 : 0,
    reason,
    namedScores: {
      quality: quality.length === 0 ? 1 : 0,
      never: never.length === 0 ? 1 : 0,
    },
  };
};
