<template>
  <div class="home">
    <assess-header></assess-header>
    <skip-question-header :qn="qn"/>
    <div class="scrollpane" role="region" :aria-label="$t('regions.questions')">
      <intro-text
        :active = "qn == -1"
        :html = "intro"
        key = "-1"
      />
      <div
        v-for="curqn in questionArray"
        :key="curqn"
        :class="{inactive: curqn != qn}"
        :aria-hidden = "curqn != qn"
      >
        <inter-question-text-list
          pos = "before"
          :qn = "curqn"
          :active="curqn == qn"
        />
        <question
          :qn="curqn"
          :active="curqn == qn"
          :getwork="1"
        />
        <inter-question-text-list
          pos = "after"
          :qn = "curqn"
          :active="curqn == qn"
        />
      </div>
    </div>
  </div>
</template>

<script>
import AssessHeader from '@/components/AssessHeader.vue';
import SkipQuestionHeader from '@/components/SkipQuestionHeader.vue';
import InterQuestionTextList from '@/components/InterQuestionTextList.vue';
import Question from '@/components/question/Question.vue';
import IntroText from '@/components/IntroText.vue';

import { store } from '../basicstore';

export default {
  name: 'skip',
  components: {
    SkipQuestionHeader,
    Question,
    InterQuestionTextList,
    AssessHeader,
    IntroText
  },
  computed: {
    qn () {
      return parseInt(this.$route.params.qn) - 1;
    },
    intro () {
      return store.assessInfo.intro;
    },
    questionArray () {
      const qnArray = {};
      for (let i = 0; i < store.assessInfo.questions.length; i++) {
        qnArray[i] = i;
      }
      return qnArray;
    }
  }
};
</script>

<style>

</style>
